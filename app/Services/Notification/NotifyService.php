<?php

namespace App\Services\Notification;

use App\Jobs\DispatchNotifyPayloadJob;
use App\Models\User;
use App\Services\Notification\Channels\DatabaseReminderChannel;
use App\Services\Notification\Channels\EmailReminderChannel;
use App\Services\Notification\Channels\FcmReminderChannel;
use App\Services\Notification\Channels\WebsocketReminderChannel;
use App\Services\Notification\Channels\WhatsappReminderChannel;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fan-out notifikasi umum (revisi Bu Kadis) -- TERPISAH dari NotificationService yang scope-nya
 * sengaja dibatasi ke reminder kunjungan H-1/hari-H (lihat docblock di sana, dan Reminder model
 * yang jadi tulang punggungnya). Dipakai untuk 3 event baru: sync SiLAKES selesai (broadcast),
 * update data pasien oleh puskesmas (puskesmas-scoped), kunjungan tenaga_kesehatan mendesak
 * (satu user). Reuse channel yang sama (ReminderChannel) supaya tidak ada abstraksi paralel.
 *
 * Target 'user' dikirim SINKRON (mirip notifyAssignedKaders() -- kecil, langsung, hasil bisa
 * langsung diketahui pemanggil). Target 'puskesmas'/'broadcast' di-fan-out lewat job terqueue
 * SATU PER USER, supaya satu user gagal kirim tidak menghalangi user lain, dan supaya broadcast
 * ke banyak user tidak blocking request yang memicunya.
 */
class NotifyService
{
    /** @var array<string, ReminderChannel> */
    private readonly array $channels;

    public function __construct(
        DatabaseReminderChannel $push,
        WhatsappReminderChannel $wa,
        EmailReminderChannel $email,
        FcmReminderChannel $fcm,
        WebsocketReminderChannel $ws,
    ) {
        // 'wa' terdaftar tapi sengaja belum dipakai di titik pemicu manapun (lihat docblock
        // NotificationService::channelsFor()) -- sama alasannya, cuma satu tempat lagi.
        $this->channels = [
            $push->key() => $push,
            $wa->key() => $wa,
            $email->key() => $email,
            $fcm->key() => $fcm,
            $ws->key() => $ws,
        ];
    }

    /**
     * @param  array<int, string>  $channelKeys
     */
    public function notify(NotifiableTarget $target, NotificationPayload $payload, array $channelKeys): void
    {
        if ($target->scope === 'user') {
            $this->deliverToUser($target->user, $payload, $channelKeys);

            return;
        }

        $this->resolveUserIds($target)->chunk(500)->each(
            fn ($ids) => $ids->each(
                fn (int $id) => DispatchNotifyPayloadJob::dispatch($id, $payload, $channelKeys)
            )
        );
    }

    /**
     * Kirim ke satu user, lewat semua channel yang diminta -- kegagalan SATU channel di-log dan
     * dilewati, channel lain tetap dicoba (graceful degrade, beda dari NotificationService::deliver()
     * yang sengaja biarkan exception naik untuk retry job).
     *
     * @param  array<int, string>  $channelKeys
     */
    public function deliverToUser(User $user, NotificationPayload $payload, array $channelKeys): void
    {
        foreach ($channelKeys as $key) {
            $channel = $this->channels[$key] ?? null;

            if ($channel === null) {
                Log::warning("NotifyService: channel '{$key}' tidak dikenal, dilewati.");

                continue;
            }

            try {
                $channel->send($user, $payload);
            } catch (Throwable $e) {
                Log::warning('NotifyService: channel gagal kirim, lanjut channel lain', [
                    'channel' => $key,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function resolveUserIds(NotifiableTarget $target)
    {
        return match ($target->scope) {
            'puskesmas' => User::where('puskesmas_id', $target->puskesmasId)->pluck('id'),
            'broadcast' => User::pluck('id'),
            'roles_in_puskesmas' => User::where('puskesmas_id', $target->puskesmasId)
                ->role($target->roles)
                ->pluck('id'),
            default => throw new RuntimeException("NotifiableTarget scope '{$target->scope}' tidak didukung untuk fan-out."),
        };
    }
}
