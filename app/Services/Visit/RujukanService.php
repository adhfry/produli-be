<?php

namespace App\Services\Visit;

use App\Models\User;
use App\Models\VisitReport;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Fase 3 (docs plan "cozy-mapping-breeze") -- halaman /dashboard/rujukan: daftar pasien yang
 * dirujuk kader/nakes ke puskesmas (VisitReport.rujukan_status IS NOT NULL, diisi otomatis
 * VisitReportService::submit() saat tindakan mencakup 'dirujuk_puskesmas'), dikonfirmasi/
 * dibatalkan admin_puskesmas/pj_prolanis.
 *
 * Scoping SENGAJA berdasar puskesmas KADER/NAKES pelapor (via assignment->kader/tenagaKesehatan),
 * BUKAN puskesmas_id_snapshot assignment (itu turunan puskesmas PASIEN) -- konsisten dengan
 * perbaikan targeting notifikasi di VisitReportService::notifyPasienDirujuk() (Fase 1/2): admin
 * puskesmas yang menerima notifikasi rujukan harus melihat baris yang SAMA persis di halaman ini.
 */
class RujukanService
{
    public function __construct(
        private readonly NotifyService $notifyService,
    ) {}
    /**
     * @return Builder<VisitReport>
     */
    public function scopedQuery(User $user): Builder
    {
        $query = VisitReport::query()->whereNotNull('rujukan_status');

        if (DataScope::isFullAccess($user)) {
            return $query;
        }

        if ($user->puskesmas_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $puskesmasId = $user->puskesmas_id;

        return $query->whereHas('assignment', function (Builder $q) use ($puskesmasId) {
            $q->whereHas('kader', fn (Builder $k) => $k->where('puskesmas_id', $puskesmasId))
                ->orWhereHas('tenagaKesehatan', fn (Builder $t) => $t->where('puskesmas_id', $puskesmasId));
        });
    }

    /**
     * @param  'dikonfirmasi'|'dibatalkan'  $status
     */
    public function konfirmasi(VisitReport $visitReport, string $status): VisitReport
    {
        if ($visitReport->rujukan_status === null) {
            throw ValidationException::withMessages([
                'rujukan' => ['Laporan kunjungan ini bukan rujukan, tidak bisa dikonfirmasi.'],
            ]);
        }

        $visitReport->update(['rujukan_status' => $status]);

        $this->notifyPelapor($visitReport, $status);

        return $visitReport;
    }

    /**
     * Notif BALIK ke kader/nakes pelapor -- GAP nyata sebelum ini: VisitReportService::
     * notifyPasienDirujuk() sudah menotif admin_puskesmas/pj_prolanis saat rujukan DIAJUKAN,
     * tapi begitu mereka konfirmasi/batalkan, pelapor tidak pernah tahu tanpa cek manual
     * (menggagalkan tujuan alur rujukan itu sendiri). Dikirim 3 kanal (push+fcm+email) --
     * sama-sama butuh respon/kesadaran cepat seperti notifyPasienDirujuk(), bukan kanal push
     * saja seperti notifikasi rutin.
     */
    private function notifyPelapor(VisitReport $visitReport, string $status): void
    {
        try {
            $assignment = $visitReport->assignment;
            $petugasUser = $assignment?->kader?->user ?? $assignment?->tenagaKesehatan?->user;

            if ($petugasUser === null) {
                return;
            }

            $patientName = $assignment->patient?->nama ?? 'pasien';
            $dikonfirmasi = $status === 'dikonfirmasi';

            $this->notifyService->notify(
                NotifiableTarget::user($petugasUser),
                new NotificationPayload(
                    type: 'rujukan_dikonfirmasi',
                    title: $dikonfirmasi ? 'Rujukan Dikonfirmasi' : 'Rujukan Dibatalkan',
                    body: $dikonfirmasi
                        ? "Rujukan pasien {$patientName} sudah dikonfirmasi puskesmas."
                        : "Rujukan pasien {$patientName} dibatalkan puskesmas.",
                    data: [
                        'type' => 'rujukan_dikonfirmasi',
                        'severity' => $dikonfirmasi ? 'info' : 'danger',
                        'assignment_id' => $assignment->id,
                        'visit_report_id' => $visitReport->id,
                        'patient_id' => $assignment->patient_id,
                        'patient_nama' => $patientName,
                        'rujukan_status' => $status,
                        'action_url' => "/app/kunjungan/{$assignment->id}",
                        'action_label' => 'Lihat Kunjungan',
                    ],
                ),
                ['push', 'fcm', 'email'],
            );
        } catch (Throwable $e) {
            Log::warning('RujukanService: gagal mengirim notifikasi konfirmasi rujukan, status tetap tersimpan', [
                'visit_report_id' => $visitReport->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
