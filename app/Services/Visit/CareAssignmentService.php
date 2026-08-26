<?php

namespace App\Services\Visit;

use App\Models\CareAssignment;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitAssignmentCompanion;
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

        return $this->ensureKaderPlanFor(
            $firstAssignment->patient_id,
            $firstAssignment->kader_id,
            $firstAssignment->assigned_by,
            $firstAssignment->puskesmas_id_snapshot,
            $firstAssignment->scheduled_date,
        );
    }

    /**
     * Inti ensureKaderPlan() di atas, dipisah supaya bisa dipanggil dari jalur assign
     * tenaga_kesehatan juga (CareAssignmentController -- kunjungan hari-1 bersama kader+nakes,
     * revisi Bu Kadis PMO) yang tidak punya VisitAssignment ber-kader_id untuk dinaiki
     * (assignment hari-1 itu MILIK nakes, kader cuma jadi companion).
     */
    public function ensureKaderPlanFor(
        int $patientId,
        int $kaderId,
        int $assignedById,
        ?int $puskesmasIdSnapshot,
        string $scheduledDate,
    ): CareAssignment {
        $existing = CareAssignment::where('patient_id', $patientId)
            ->where('worker_type', 'kader')
            ->where('kader_id', $kaderId)
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CareAssignment::create([
            'patient_id' => $patientId,
            'worker_type' => 'kader',
            'kader_id' => $kaderId,
            'assigned_by' => $assignedById,
            'puskesmas_id_snapshot' => $puskesmasIdSnapshot,
            'status' => 'active',
            'last_triggered_at' => $scheduledDate,
        ]);
    }

    /**
     * Varian ensureKaderPlanFor() utk penugasan multi-tanggal (permintaan user,
     * VisitAssignmentService::assignMultipleDates()) -- kalau plan BELUM ada, buat baru dengan
     * last_triggered_at = tanggal TERAKHIR (paling jauh ke depan) dari batch. Kalau plan SUDAH
     * ada, MAJUKAN last_triggered_at ke tanggal itu (JANGAN PERNAH mundur -- ensureKaderPlanFor()
     * biasa idempotent/no-op kalau sudah ada, itu cukup utk assign() satu-tanggal, tapi DI SINI
     * kita SENGAJA majukan supaya scan cadence harian (CareAssignmentCadenceService) tidak
     * langsung generate kunjungan duplikat tepat setelah batch manual ini -- next-due dihitung
     * dari last_triggered_at, jadi ini yang membuat auto-generate "melompati" tanggal yang sudah
     * dijadwalkan manual).
     */
    public function ensureKaderPlanAdvancedTo(
        int $patientId,
        int $kaderId,
        int $assignedById,
        ?int $puskesmasIdSnapshot,
        string $lastScheduledDate,
    ): CareAssignment {
        $existing = CareAssignment::where('patient_id', $patientId)
            ->where('worker_type', 'kader')
            ->where('kader_id', $kaderId)
            ->where('status', 'active')
            ->first();

        if ($existing === null) {
            return CareAssignment::create([
                'patient_id' => $patientId,
                'worker_type' => 'kader',
                'kader_id' => $kaderId,
                'assigned_by' => $assignedById,
                'puskesmas_id_snapshot' => $puskesmasIdSnapshot,
                'status' => 'active',
                'last_triggered_at' => $lastScheduledDate,
            ]);
        }

        if ($existing->last_triggered_at === null || $existing->last_triggered_at->lt($lastScheduledDate)) {
            $existing->update(['last_triggered_at' => $lastScheduledDate]);
        }

        return $existing;
    }

    /**
     * PJ Prolanis/admin_puskesmas menugaskan tenaga_kesehatan ke pasien -- beda dari kader,
     * TIDAK ada jalur assign satu-kali existing untuk dinaiki, jadi plan DAN kunjungan pertama
     * dibuat sekaligus di sini.
     */
    /**
     * $kader opsional -- kunjungan hari-1 bersama (revisi Bu Kadis PMO): kalau diisi, kader
     * ditandai sebagai pendamping (companion) kunjungan pertama nakes ini (hadir fisik, TIDAK
     * submit laporan terpisah -- nakes yang isi laporan lengkap hari itu) DAN rencana kunjungan
     * mingguan kader langsung diaktifkan supaya kunjungan PMO mingguan berikutnya otomatis
     * ter-generate tanpa perlu assign kader terpisah lagi.
     */
    public function assignTenagaKesehatan(
        PatientsCache $patient,
        TenagaKesehatan $tenagaKesehatan,
        User $assignedBy,
        string $scheduledDate,
        ?Kader $kader = null,
    ): CareAssignment {
        $this->ensureTenagaKesehatanAvailable($tenagaKesehatan, $patient);

        if ($kader !== null) {
            $this->ensureKaderAvailableForJointVisit($kader, $patient);
        }

        $visit = null;

        $plan = DB::transaction(function () use ($patient, $tenagaKesehatan, $assignedBy, $scheduledDate, $kader, &$visit) {
            $plan = CareAssignment::create([
                'patient_id' => $patient->id,
                'worker_type' => 'tenaga_kesehatan',
                'tenaga_kesehatan_id' => $tenagaKesehatan->id,
                'assigned_by' => $assignedBy->id,
                'puskesmas_id_snapshot' => $patient->puskesmas_id ?? $tenagaKesehatan->puskesmas_id,
                'status' => 'active',
            ]);

            $visit = $this->createVisit($plan, $scheduledDate, 'cadence_generated');

            if ($kader !== null) {
                VisitAssignmentCompanion::create([
                    'assignment_id' => $visit->id,
                    'kader_id' => $kader->id,
                ]);

                $this->ensureKaderPlanFor(
                    $patient->id,
                    $kader->id,
                    $assignedBy->id,
                    $visit->puskesmas_id_snapshot,
                    $scheduledDate,
                );
            }

            return $plan;
        });

        // Di luar transaction (konsisten dgn VisitReportService::notifyReportSubmitted) --
        // panggilan HTTP ke Firebase tidak boleh ikut menahan/terjebak rollback DB.
        $this->notifyVisitAssigned($visit, $scheduledDate);

        return $plan;
    }

    /**
     * Kader pendamping kunjungan hari-1 -- validasi sama seperti companion kunjungan kader biasa
     * (VisitAssignmentService::ensureCompanionsAvailable(): aktif + sepuskesmas), dibandingkan
     * ke PASIEN di sini (bukan ke kader primer lain, karena tidak ada kader primer di kunjungan
     * hari-1 ini -- nakes yang primer).
     */
    private function ensureKaderAvailableForJointVisit(Kader $kader, PatientsCache $patient): void
    {
        if (! $kader->status_aktif) {
            throw ValidationException::withMessages([
                'kader_id' => ['Kader tidak aktif.'],
            ]);
        }

        if ($patient->puskesmas_id !== null && $kader->puskesmas_id !== $patient->puskesmas_id) {
            throw ValidationException::withMessages([
                'kader_id' => ['Kader bukan dari puskesmas yang sama dengan pasien.'],
            ]);
        }
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
        $scheduledDate = now()->toDateString();
        $visit = $this->createVisit($plan, $scheduledDate, 'cadence_generated');
        $this->notifyVisitAssigned($visit, $scheduledDate);

        return $visit;
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
                // 3 kanal -- "mendesak" di judul bukan basa-basi, nakes perlu tahu SEGERA meski
                // sedang tidak buka app (beda dari notifyVisitAssigned() di bawah yang rutin).
                ['push', 'fcm', 'email'],
            );
        }

        return $visit;
    }

    /**
     * SEBELUMNYA: assignTenagaKesehatan()/generateDueVisit() sama sekali tidak menotif
     * tenaga_kesehatan (beda dari createAdhocVisit() di atas yang sudah push+fcm) -- nakes bisa
     * tidak sadar ada kunjungan berulang baru sampai buka app sendiri. Type 'visit_assigned' SAMA
     * dengan VisitAssignmentService::notifyAssignedKaders() (sisi kader) supaya frontend cukup
     * satu formatNotification()/notifIcon() case untuk keduanya.
     */
    private function notifyVisitAssigned(VisitAssignment $visit, string $scheduledDate): void
    {
        $assignee = $visit->assigneeUser();

        if ($assignee === null) {
            return;
        }

        $this->notifyService->notify(
            NotifiableTarget::user($assignee),
            new NotificationPayload(
                type: 'visit_assigned',
                title: 'Tugas Kunjungan Baru',
                body: "Kunjungan baru dijadwalkan {$scheduledDate}.",
                data: [
                    'type' => 'visit_assigned',
                    'task_count' => 1,
                    'scheduled_date' => $scheduledDate,
                    'action_url' => '/app/tugas',
                    'action_label' => 'Lihat Tugas',
                ],
            ),
            ['push', 'fcm'],
        );
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
