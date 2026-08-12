<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kirim satu NotificationPayload ke SATU user lewat channel yang diminta -- unit kerja terkecil
 * dari NotifyService::notify() untuk target puskesmas/broadcast (lihat docblock di sana). Job
 * per-user (bukan satu job untuk banyak user sekaligus) supaya satu user gagal tidak menghalangi
 * user lain, dan supaya broadcast ke ratusan user tidak jadi satu job raksasa yang lama.
 */
class DispatchNotifyPayloadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<int, string>  $channelKeys
     */
    public function __construct(
        public readonly int $userId,
        public readonly NotificationPayload $payload,
        public readonly array $channelKeys,
    ) {}

    public function handle(NotifyService $service): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $service->deliverToUser($user, $this->payload, $this->channelKeys);
    }
}
