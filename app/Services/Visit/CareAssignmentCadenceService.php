<?php

namespace App\Services\Visit;

use App\Models\CareAssignment;
use App\Models\RiskClassification;
use App\Models\VisitAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Scan harian semua care_assignments aktif, generate kunjungan yang sudah due (revisi Bu
 * Kadis) -- pola polling yang SAMA dengan NotificationService::scheduleUpcomingReminders()
 * (bukan event listener, codebase ini tidak ada infrastruktur Events/Listeners sama sekali,
 * lihat docs plan). cadenceDaysFor() BACA ULANG level risiko pasien SETIAP kali scan jalan --
 * itulah cara kadensi tenaga_kesehatan otomatis menyesuaikan begitu pasien naik/turun level,
 * TANPA perlu event/listener sama sekali.
 */
class CareAssignmentCadenceService
{
    public function __construct(private readonly CareAssignmentService $service) {}

    public function generateDueVisits(): int
    {
        $count = 0;

        CareAssignment::where('status', 'active')->chunkById(200, function ($plans) use (&$count) {
            foreach ($plans as $plan) {
                if (! $this->isDue($plan) || $this->hasOpenVisit($plan)) {
                    continue;
                }

                $this->service->generateDueVisit($plan);
                $count++;
            }
        });

        return $count;
    }

    private function isDue(CareAssignment $plan): bool
    {
        if ($plan->last_triggered_at === null) {
            return true;
        }

        return $plan->last_triggered_at->diffInDays(Carbon::today()) >= $this->cadenceDaysFor($plan);
    }

    /**
     * Proyeksi tanggal kunjungan berikutnya yang akan otomatis di-generate scan harian
     * (generateDueVisits() di atas) -- MURNI perhitungan, TIDAK menulis apa pun ke DB (permintaan
     * user, fitur "lihat jadwal cadence" di halaman pasien -- sebelumnya cadence otomatis ini
     * jalan tanpa ada tampilan APA PUN ke admin, jadi tidak terlihat kapan kunjungan berikutnya
     * bakal muncul). $count kunjungan ke depan, jarak antar tanggal = cadenceDaysFor() (bisa
     * berubah kalau prioritas pasien nakes berubah di antara proyeksi -- makanya ini SELALU
     * dihitung ulang saat diminta, bukan disimpan).
     *
     * @return array<int, \Illuminate\Support\Carbon>
     */
    public function upcomingDates(CareAssignment $plan, int $count = 4): array
    {
        $days = $this->cadenceDaysFor($plan);
        $anchor = $plan->last_triggered_at ?? Carbon::today();

        $dates = [];
        for ($i = 1; $i <= $count; $i++) {
            $dates[] = $anchor->copy()->addDays($days * $i);
        }

        return $dates;
    }

    /**
     * true kalau plan ini SEDANG punya kunjungan pending/in_progress -- scan harian TIDAK akan
     * generate apa pun sampai itu selesai (lihat hasOpenVisit() di bawah), jadi proyeksi
     * upcomingDates() di atas belum pasti mulai berjalan sebelum kunjungan terbuka ini beres.
     */
    public function isBlockedByOpenVisit(CareAssignment $plan): bool
    {
        return $this->hasOpenVisit($plan);
    }

    /**
     * Geser jangkar cadence supaya kunjungan berikutnya (upcomingDates()[0]) jatuh PERSIS di
     * $nextDate (permintaan user, fitur "atur ulang jadwal") -- last_triggered_at diset mundur
     * $nextDate - cadenceDaysFor(), bukan menulis kolom baru, supaya seluruh proyeksi/scan
     * harian (generateDueVisits/upcomingDates) otomatis ikut tanpa perlu logic khusus di tempat
     * lain. Menolak (ValidationException, pola sama seperti CareAssignmentService) kalau plan
     * sudah tidak aktif atau masih diblokir kunjungan terbuka -- geser jadwal selagi ada
     * kunjungan pending/in_progress bisa bikin dua sumber kebenaran soal "kunjungan berikutnya
     * kapan", harus diselesaikan/dibatalkan dulu.
     */
    public function rescheduleTo(CareAssignment $plan, Carbon $nextDate): void
    {
        if ($plan->status !== 'active') {
            throw ValidationException::withMessages([
                'next_date' => ['Rencana kunjungan ini sudah tidak aktif, tidak bisa diatur ulang.'],
            ]);
        }

        if ($this->hasOpenVisit($plan)) {
            throw ValidationException::withMessages([
                'next_date' => ['Masih ada kunjungan yang belum selesai untuk rencana ini -- selesaikan atau batalkan dulu sebelum mengatur ulang jadwal.'],
            ]);
        }

        $plan->update([
            'last_triggered_at' => $nextDate->copy()->subDays($this->cadenceDaysFor($plan)),
        ]);
    }

    private function cadenceDaysFor(CareAssignment $plan): int
    {
        if ($plan->worker_type === 'kader') {
            return (int) config('produli.cadence.kader_days');
        }

        $isBerat = RiskClassification::where('patient_id', $plan->patient_id)
            ->where('is_latest', true)
            ->where('level', 'berat')
            ->exists();

        return $isBerat
            ? (int) config('produli.cadence.tenaga_kesehatan_days_berat')
            : (int) config('produli.cadence.tenaga_kesehatan_days');
    }

    /**
     * Jangan generate kunjungan baru kalau plan ini masih punya kunjungan pending/in_progress
     * yang belum selesai -- cegah numpuk kunjungan due berulang-ulang sebelum yang lama
     * ditindaklanjuti.
     */
    private function hasOpenVisit(CareAssignment $plan): bool
    {
        return VisitAssignment::where('care_assignment_id', $plan->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();
    }
}
