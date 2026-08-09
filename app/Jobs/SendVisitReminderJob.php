<?php

namespace App\Jobs;

use App\Models\Reminder;
use App\Services\Notification\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Kirim SATU reminder kunjungan kader (docs/planning/02 §8) -- job TERPISAH dengan retry, pola
 * sama seperti SyncFieldUpdateToSilakesJob: dipanggil async dari
 * NotificationService::dispatchDueReminders() supaya command scheduler harian tidak blocking
 * menunggu tiap pengiriman selesai satu per satu (Horizon sudah ada di stack).
 */
class SendVisitReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $reminderId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(NotificationService $service): void
    {
        $service->deliver($this->reminderId);
    }

    public function failed(?Throwable $exception): void
    {
        Reminder::whereKey($this->reminderId)->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage(),
        ]);
    }
}
