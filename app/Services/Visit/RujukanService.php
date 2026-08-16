<?php

namespace App\Services\Visit;

use App\Models\User;
use App\Models\VisitReport;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

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

        return $visitReport;
    }
}
