<?php

namespace App\Services\Prolanis;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\ProlanisSchedule;
use App\Models\User;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Penjadwalan otomatis kegiatan Prolanis (permintaan user) -- jadwal periksa berikutnya
 * dihitung dari lab_results_cache.tanggal_periksa TERBARU pasien (BUKAN created_at, itu cuma
 * kapan baris masuk ke cache PRODULI) + interval sesuai jenis_prolanis. Lihat docblock migration
 * create_prolanis_schedules_table utk kenapa SATU baris aktif per pasien (bukan riwayat berversi).
 */
class ProlanisScheduleService
{
    public function __construct(private readonly NotifyService $notifyService) {}

    /**
     * @return Builder<ProlanisSchedule>
     */
    public function scopedQuery(User $user): Builder
    {
        $query = ProlanisSchedule::query()->with(['patient', 'puskesmas', 'updatedBy']);

        if (DataScope::isFullAccess($user)) {
            return $query;
        }

        if ($user->puskesmas_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('puskesmas_id', $user->puskesmas_id);
    }

    private function intervalMonthsFor(?string $jenisProlanis): int
    {
        return match ($jenisProlanis) {
            'DM' => (int) config('produli.prolanis_schedule.dm_interval_months'),
            'HT' => (int) config('produli.prolanis_schedule.ht_interval_months'),
            // Jenis lain/belum diketahui -- pakai interval HT (lebih longgar/6 bulan) sbg
            // default aman, DARIPADA diam-diam dilewati (pasien Prolanis tetap perlu terjadwal
            // meski jenisnya belum tercatat SiLAKES).
            default => (int) config('produli.prolanis_schedule.ht_interval_months'),
        };
    }

    /**
     * Generate/perbarui jadwal SEMUA pasien Prolanis yang punya minimal 1 hasil lab -- dipanggil
     * produli:generate-prolanis-schedules (dailyAt, routes/console.php). Baris ber-
     * is_manual_override=true TIDAK PERNAH disentuh (staf sudah menetapkan tanggal sendiri).
     * Baris yang source_lab_date-nya SUDAH sama dgn data lab terbaru saat ini juga dilewati
     * (idempotent, tidak menulis ulang tanpa perubahan).
     */
    public function generateSchedules(): int
    {
        $updated = 0;

        PatientsCache::query()
            ->where('is_prolanis', true)
            ->whereHas('labResults')
            ->chunkById(200, function ($patients) use (&$updated) {
                foreach ($patients as $patient) {
                    $latestLabDate = LabResultCache::where('patient_id', $patient->external_patient_id)
                        ->max('tanggal_periksa');

                    if ($latestLabDate === null) {
                        continue;
                    }

                    $existing = ProlanisSchedule::where('patient_id', $patient->id)->first();

                    if ($existing?->is_manual_override) {
                        continue;
                    }

                    if ($existing && $existing->source_lab_date?->toDateString() === $latestLabDate) {
                        continue; // sudah mutakhir, tidak ada lab baru sejak terakhir dihitung.
                    }

                    $scheduledDate = Carbon::parse($latestLabDate)
                        ->addMonthsNoOverflow($this->intervalMonthsFor($patient->jenis_prolanis));

                    ProlanisSchedule::updateOrCreate(
                        ['patient_id' => $patient->id],
                        [
                            'puskesmas_id' => $patient->puskesmas_id,
                            'jenis_prolanis' => $patient->jenis_prolanis,
                            'source_lab_date' => $latestLabDate,
                            'scheduled_date' => $scheduledDate,
                            'status' => 'terjadwal',
                            'notified_at' => null, // data lab baru -> jadwal baru, reminder blm terkirim.
                        ],
                    );
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Ubah tanggal jadwal secara manual (permintaan user, "manajemen tanggal per puskesmas") --
     * menandai is_manual_override=true supaya generateSchedules() tidak pernah menimpanya lagi.
     */
    public function reschedule(ProlanisSchedule $schedule, string $newDate, User $updatedBy): ProlanisSchedule
    {
        $schedule->update([
            'scheduled_date' => $newDate,
            'is_manual_override' => true,
            'updated_by' => $updatedBy->id,
        ]);

        return $schedule;
    }

    public function updateStatus(ProlanisSchedule $schedule, string $status, User $updatedBy): ProlanisSchedule
    {
        if (! in_array($status, ['terjadwal', 'selesai', 'dibatalkan'], true)) {
            throw ValidationException::withMessages(['status' => ['Status tidak valid.']]);
        }

        $schedule->update(['status' => $status, 'updated_by' => $updatedBy->id]);

        return $schedule;
    }

    /**
     * Notifikasi H-1 minggu (permintaan user) -- 1 notifikasi per puskesmas berisi daftar
     * nama+tanggal pasien yang jadwalnya jatuh persis reminder_days_before hari dari sekarang,
     * KHUSUS status='terjadwal' (bukan yang sudah selesai/dibatalkan). notified_at jadi guard
     * idempotency -- dipanggil produli:notify-prolanis-schedule-reminders (dailyAt).
     */
    public function sendDueReminders(): int
    {
        $targetDate = Carbon::today()->addDays((int) config('produli.prolanis_schedule.reminder_days_before'));

        $due = ProlanisSchedule::query()
            ->with(['patient', 'puskesmas'])
            ->where('status', 'terjadwal')
            ->whereNull('notified_at')
            ->whereDate('scheduled_date', $targetDate)
            ->whereNotNull('puskesmas_id')
            ->get()
            ->groupBy('puskesmas_id');

        $notifiedPuskesmasCount = 0;

        foreach ($due as $puskesmasId => $schedules) {
            try {
                $names = $schedules->map(fn (ProlanisSchedule $s) => sprintf(
                    '%s (%s)',
                    $s->patient?->nama ?? 'Pasien tidak diketahui',
                    $s->scheduled_date->translatedFormat('d M Y'),
                ))->implode(', ');

                $puskesmasNama = $schedules->first()->puskesmas?->nama ?? 'puskesmas Anda';

                $this->notifyService->notify(
                    NotifiableTarget::rolesInPuskesmas(['admin_puskesmas', 'pj_prolanis'], (int) $puskesmasId),
                    new NotificationPayload(
                        type: 'prolanis_schedule_reminder',
                        title: 'Pengingat Jadwal Prolanis Minggu Depan',
                        body: sprintf(
                            '%d pasien perlu periksa Prolanis di %s pada %s: %s',
                            $schedules->count(),
                            $puskesmasNama,
                            $targetDate->translatedFormat('d M Y'),
                            $names,
                        ),
                        data: [
                            'type' => 'prolanis_schedule_reminder',
                            'puskesmas_id' => (int) $puskesmasId,
                            'scheduled_date' => $targetDate->toDateString(),
                            'count' => $schedules->count(),
                            'patients' => $schedules->map(fn (ProlanisSchedule $s) => [
                                'patient_id' => $s->patient_id,
                                'nama' => $s->patient?->nama,
                                'scheduled_date' => $s->scheduled_date->toDateString(),
                            ])->values(),
                            'action_url' => '/dashboard/jadwal-prolanis',
                            'action_label' => 'Lihat Jadwal',
                        ],
                    ),
                    // 'email' + 'push'/'fcm' (BUKAN 'wa' -- kanal itu terdaftar tapi SENGAJA
                    // belum dipakai di manapun, bot WA masih dalam pembuatan, lihat docblock
                    // NotificationService::channelsFor()). Tinggal tambah 'wa' ke array ini
                    // nanti begitu bot-nya siap, arsitekturnya sudah siap pakai.
                    ['push', 'fcm', 'email'],
                );

                ProlanisSchedule::whereIn('id', $schedules->pluck('id'))->update(['notified_at' => now()]);
                $notifiedPuskesmasCount++;
            } catch (Throwable $e) {
                Log::warning('ProlanisScheduleService: gagal kirim reminder H-1 minggu', [
                    'puskesmas_id' => $puskesmasId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $notifiedPuskesmasCount;
    }
}
