<?php

namespace App\Services\Visit;

use App\Models\CareAssignment;
use App\Models\PatientsCache;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rencana kunjungan BERULANG (revisi Bu Kadis) -- lihat docblock migration
 * create_care_assignments_table untuk konsep dasarnya. VisitAssignmentService (assign kader
 * satu-kali) TIDAK diubah sama sekali oleh service ini -- ensureKaderPlan() dipanggil SETELAH
 * assign()/assignBulk() sukses (dari controller), murni menambah lapisan "tugas berulang" di
 * atas assignment satu-kali yang sudah ada.
 */
class CareAssignmentService
{
    public function __construct(private readonly NotifyService $notifyService) {}

    /**
     * Dipanggil setelah kader pertama kali berhasil di-assign ke pasien (VisitAssignmentService::
     * assign()/assignBulk()). Idempotent -- no-op kalau plan aktif untuk pasangan pasien+kader
     * ini sudah ada, supaya re-assign kader yang sama setelah kunjungan selesai tidak bikin plan
     * duplikat.
     */
    public function ensureKaderPlan(VisitAssignment $firstAssignment): ?CareAssignment
    {
        if ($firstAssignment->kader_id === null) {
            return null;
        }

        $existing = CareAssignment::where('patient_id', $firstAssignment->patient_id)
            ->where('worker_type', 'kader')
            ->where('kader_id', $firstAssignment->kader_id)
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CareAssignment::create([
            'patient_id' => $firstAssignment->patient_id,
            'worker_type' => 'kader',
            'kader_id' => $firstAssignment->kader_id,
            'assigned_by' => $firstAssignment->assigned_by,
            'puskesmas_id_snapshot' => $firstAssignment->puskesmas_id_snapshot,
            'status' => 'active',
            'last_triggered_at' => $firstAssignment->scheduled_date,
        ]);
    }

    /**
     * PJ Prolanis/admin_puskesmas menugaskan tenaga_kesehatan ke pasien -- beda dari kader,
     * TIDAK ada jalur assign satu-kali existing untuk dinaiki, jadi plan DAN kunjungan pertama
     * dibuat sekaligus di sini.
     */
    public function assignTenagaKesehatan(
        PatientsCache $patient,
        TenagaKesehatan $tenagaKesehatan,
        User $assignedBy,
        string $scheduledDate,
    ): CareAssignment {
        $this->ensureTenagaKesehatanAvailable($tenagaKesehatan, $patient);

        return DB::transaction(function () use ($patient, $tenagaKesehatan, $assignedBy, $scheduledDate) {
            $plan = CareAssignment::create([
                'patient_id' => $patient->id,
                'worker_type' => 'tenaga_kesehatan',
                'tenaga_kesehatan_id' => $tenagaKesehatan->id,
                'assigned_by' => $assignedBy->id,
                'puskesmas_id_snapshot' => $patient->puskesmas_id ?? $tenagaKesehatan->puskesmas_id,
                'status' => 'active',
            ]);

            $this->createVisit($plan, $scheduledDate, 'cadence_generated');

            return $plan;
        });
    }

    private function ensureTenagaKesehatanAvailable(TenagaKesehatan $tenagaKesehatan, PatientsCache $patient): void
    {
        if (! $tenagaKesehatan->status_aktif) {
            throw ValidationException::withMessages([
                'tenaga_kesehatan' => ['Tenaga kesehatan tidak aktif.'],
            ]);
        }

        if ($patient->puskesmas_id !== null && $tenagaKesehatan->puskesmas_id !== $patient->puskesmas_id) {
            throw ValidationException::withMessages([
                'tenaga_kesehatan' => ['Tenaga kesehatan bukan dari puskesmas yang sama dengan pasien.'],
            ]);
        }

        $sudahAda = CareAssignment::where('patient_id', $patient->id)
            ->where('worker_type', 'tenaga_kesehatan')
            ->where('tenaga_kesehatan_id', $tenagaKesehatan->id)
            ->where('status', 'active')
            ->exists();

        if ($sudahAda) {
            throw ValidationException::withMessages([
                'tenaga_kesehatan' => ['Pasien ini sudah punya rencana kunjungan aktif ke tenaga kesehatan yang sama.'],
            ]);
        }
    }

    /**
     * Dipanggil CareAssignmentCadenceService saat plan sudah due -- generate kunjungan
     * berikutnya, update last_triggered_at (anchor hitungan due berikutnya).
     */
    public function generateDueVisit(CareAssignment $plan): VisitAssignment
    {
        return $this->createVisit($plan, now()->toDateString(), 'cadence_generated');
    }

    /**
     * PJ Prolanis/admin_puskesmas minta kunjungan tenaga_kesehatan TAMBAHAN di luar jadwal
     * normal (pasien butuh pemeriksaan intensif mendesak) -- reset last_triggered_at ke tanggal
     * kunjungan mendesak ini, jadi hitungan kunjungan RUTIN berikutnya mulai dari titik ini
     * (bukan dari jadwal lama), TANPA kode khusus tambahan (lihat docblock migration
     * create_care_assignments_table).
     */
    public function createAdhocVisit(CareAssignment $plan, User $requestedBy, string $scheduledDate): VisitAssignment
    {
        if ($plan->worker_type !== 'tenaga_kesehatan') {
            throw ValidationException::withMessages([
                'care_assignment' => ['Kunjungan tambahan mendesak cuma berlaku untuk rencana tenaga kesehatan.'],
            ]);
        }

        $visit = $this->createVisit($plan, $scheduledDate, 'adhoc');

        $assignee = $plan->assigneeUser();

        if ($assignee !== null) {
            $this->notifyService->notify(
                NotifiableTarget::user($assignee),
                new NotificationPayload(
                    type: 'care_visit_adhoc',
                    title: 'Kunjungan Tambahan Mendesak',
                    body: "Kunjungan intensif tambahan untuk {$plan->patient->nama} dijadwalkan {$scheduledDate}.",
                    data: [
                        'type' => 'care_visit_adhoc',
                        'care_assignment_id' => $plan->id,
                        'visit_assignment_id' => $visit->id,
                        'patient_nama' => $plan->patient->nama,
                        'scheduled_date' => $scheduledDate,
                    ],
                ),
                ['push', 'fcm'],
            );
        }

        return $visit;
    }

    private function createVisit(CareAssignment $plan, string $scheduledDate, string $origin): VisitAssignment
    {
        return DB::transaction(function () use ($plan, $scheduledDate, $origin) {
            $priority = $plan->patient->latestRiskClassification?->level ?? 'ringan';

            $visit = VisitAssignment::create([
                'patient_id' => $plan->patient_id,
                'kader_id' => $plan->worker_type === 'kader' ? $plan->kader_id : null,
                'tenaga_kesehatan_id' => $plan->worker_type === 'tenaga_kesehatan' ? $plan->tenaga_kesehatan_id : null,
                'care_assignment_id' => $plan->id,
                'assigned_by' => $plan->assigned_by,
                'scheduled_date' => $scheduledDate,
                'status' => 'pending',
                'priority' => in_array($priority, ['ringan', 'sedang', 'berat'], true) ? $priority : 'ringan',
                'visit_origin' => $origin,
                'puskesmas_id_snapshot' => $plan->puskesmas_id_snapshot,
            ]);

            $plan->update(['last_triggered_at' => $scheduledDate]);

            return $visit;
        });
    }
}
