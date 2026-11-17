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
     * CURRENT (is_latest=true) kalau $asOf null, atau REKONSTRUKSI historis kalau $asOf diisi:
     * pemeriksaan TERAKHIR (assessment_date) yang tanggalnya <= $asOf, dengan perhitungan
     * terbarunya.
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

        // Revisi 2026-11-17 (blueprint docs/planning/16 Fase 4): kondisi "per tanggal" diukur dari
        // TANGGAL PEMERIKSAAN (assessment_date), bukan computed_at. computed_at menumpuk di tanggal
        // hitung ulang massal (9.461 dari 12.035 baris dihitung 12 & 17 Agustus 2026), sehingga
        // periode sebelum Agustus dulu tampil kosong. Untuk pemeriksaan yang sama dipakai
        // perhitungan TERBARU (aturan yang sama dengan dedup riwayat risiko pasien). Baris lama
        // tanpa assessment_date memakai tanggal computed_at.
        $tanggal = 'COALESCE(risk_classifications.assessment_date, DATE(risk_classifications.computed_at))';
        $batas = $asOf->toDateString();
        $pasien = (clone $scopedPatients)->select('id');

        $tanggalTerakhir = RiskClassification::query()
            ->select('risk_classifications.patient_id')
            ->selectRaw("MAX({$tanggal}) as tgl_efektif")
            ->whereRaw("{$tanggal} <= ?", [$batas])
            ->whereIn('risk_classifications.patient_id', $pasien)
            ->groupBy('risk_classifications.patient_id');

        $hitungTerakhir = RiskClassification::query()
            ->joinSub($tanggalTerakhir, 'tgl_per_pasien', function ($join) use ($tanggal) {
                $join->on('risk_classifications.patient_id', '=', 'tgl_per_pasien.patient_id')
                    ->on(\Illuminate\Support\Facades\DB::raw($tanggal), '=', 'tgl_per_pasien.tgl_efektif');
            })
            ->select('risk_classifications.patient_id', 'tgl_per_pasien.tgl_efektif')
            ->selectRaw('MAX(risk_classifications.computed_at) as max_computed_at')
            ->groupBy('risk_classifications.patient_id', 'tgl_per_pasien.tgl_efektif');

        // TIDAK select('risk_classifications.*') di sini -- sebagian caller (DashboardService)
        // pasang selectRaw()+groupBy() sendiri; mencampur '*' bikin GROUP BY tidak lengkap di
        // bawah MySQL ONLY_FULL_GROUP_BY. Caller yang butuh baris utuh (PatientController) --
        // tinggal ->select('risk_classifications.*') sendiri di sisi pemanggil.
        return RiskClassification::query()
            ->joinSub($hitungTerakhir, 'latest_as_of', function ($join) use ($tanggal) {
                $join->on('risk_classifications.patient_id', '=', 'latest_as_of.patient_id')
                    ->on('risk_classifications.computed_at', '=', 'latest_as_of.max_computed_at')
                    ->on(\Illuminate\Support\Facades\DB::raw($tanggal), '=', 'latest_as_of.tgl_efektif');
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
