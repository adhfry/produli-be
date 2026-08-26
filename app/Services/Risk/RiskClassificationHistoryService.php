<?php

namespace App\Services\Risk;

use App\Models\PatientsCache;
use App\Models\RiskClassification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rekonstruksi RiskClassification EFEKTIF per pasien pada satu titik waktu -- diekstrak dari
 * DashboardService::effectiveRiskClassifications() (permintaan user, fitur periode bulanan
 * dashboard/pasien) supaya logika "status AS OF tanggal tertentu" cuma ada di SATU tempat,
 * dipakai bersama DashboardService (agregat kecamatan/desa/puskesmas) DAN PatientController
 * (per-pasien, fitur bandingkan periode) -- keduanya WAJIB konsisten kalau ditanya "kondisi
 * per tanggal X", tidak boleh py 2 logika terpisah yang bisa drift.
 */
class RiskClassificationHistoryService
{
    /**
     * CURRENT (is_latest=true) kalau $asOf null, atau REKONSTRUKSI historis (baris computed_at
     * TERBESAR yang masih <= $asOf per pasien) kalau $asOf diisi.
     *
     * @param  Builder<PatientsCache>  $scopedPatients
     * @return Builder<RiskClassification>
     */
    public function effectiveQuery(Builder $scopedPatients, ?Carbon $asOf): Builder
    {
        if ($asOf === null) {
            return RiskClassification::query()
                ->where('is_latest', true)
                ->whereIn('patient_id', (clone $scopedPatients)->select('id'));
        }

        $latestPerPatient = RiskClassification::query()
            ->select('patient_id')
            ->selectRaw('MAX(computed_at) as max_computed_at')
            ->where('computed_at', '<=', $asOf)
            ->whereIn('patient_id', (clone $scopedPatients)->select('id'))
            ->groupBy('patient_id');

        // TIDAK select('risk_classifications.*') di sini -- sebagian caller (DashboardService)
        // pasang selectRaw()+groupBy() sendiri; mencampur '*' bikin GROUP BY tidak lengkap di
        // bawah MySQL ONLY_FULL_GROUP_BY. Caller yang butuh baris utuh (PatientController) --
        // tinggal ->select('risk_classifications.*') sendiri di sisi pemanggil.
        return RiskClassification::query()
            ->joinSub($latestPerPatient, 'latest_as_of', function ($join) {
                $join->on('risk_classifications.patient_id', '=', 'latest_as_of.patient_id')
                    ->on('risk_classifications.computed_at', '=', 'latest_as_of.max_computed_at');
            });
    }

    /**
     * Satu pasien saja (fitur bandingkan periode /dashboard/pasien/{id}) -- wrapper tipis di atas
     * effectiveQuery() dengan select('risk_classifications.*') supaya hasilnya langsung berupa
     * model RiskClassification utuh, bukan cuma kolom agregat.
     */
    public function effectiveFor(int $patientId, ?Carbon $asOf): ?RiskClassification
    {
        $scopedPatients = PatientsCache::query()->where('id', $patientId);

        return $this->effectiveQuery($scopedPatients, $asOf)
            ->select('risk_classifications.*')
            ->first();
    }
}
