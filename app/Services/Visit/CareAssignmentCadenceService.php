<?php

namespace App\Services\Visit;

use App\Models\CareAssignment;
use App\Models\RiskClassification;
use App\Models\VisitAssignment;
use Illuminate\Support\Carbon;

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
