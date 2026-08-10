<?php

namespace App\Services\Dashboard;

use App\DTO\DashboardSummary;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Services\Patient\PatientQueryService;
use App\Services\Visit\VisitAssignmentService;
use App\Support\DataScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ringkasan dashboard per role (docs/planning/02 §7/§13) -- jumlah pasien per level risiko,
 * jumlah assignment kunjungan per status, dan perluasan §13 (kader_aktif_count,
 * tingkat_kepatuhan, aktivitas_hari_ini, risiko_per_kecamatan), semua dalam scope data yang
 * sama dengan GET /api/v1/patients dan GET /api/v1/visit-assignments.
 *
 * Filter opsional (docs/planning §7 lanjutan -- kebutuhan enterprise/audit):
 * - $puskesmasId: HANYA berlaku utk full-access (super_admin) -- persempit ke SATU puskesmas.
 *   admin_puskesmas/pj_prolanis SUDAH terkunci ke puskesmas sendiri lewat scopedQuery(), input
 *   ini diabaikan utk mereka (tidak relevan, bukan celah keamanan -- mereka tidak pernah bisa
 *   melihat puskesmas lain apa pun nilainya).
 * - $dateFrom/$dateTo: metrik kunjungan (visits_per_status/tingkat_kepatuhan/total_assignments)
 *   difilter scheduled_date BETWEEN. Metrik risiko pasien (patients_per_risk_level/
 *   risiko_per_kecamatan/risiko_per_desa/total_patients) direkonstruksi "as of" $dateTo lewat
 *   RiskClassification.computed_at (baris klasifikasi TERBARU yang computed_at <= $dateTo per
 *   pasien) -- BUKAN is_latest=true (itu selalu kondisi SEKARANG, tidak berguna utk query
 *   historis). aktivitas_hari_ini TETAP selalu "hari ini" kalau range tidak diisi (default),
 *   ikut $dateFrom/$dateTo kalau diisi.
 */
class DashboardService
{
    private const RISK_LEVELS = ['ringan', 'sedang', 'berat'];

    private const VISIT_STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public function __construct(
        private readonly PatientQueryService $patientQuery,
        private readonly VisitAssignmentService $visitAssignmentService,
    ) {}

    public function summaryFor(
        User $user,
        ?int $puskesmasId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
    ): DashboardSummary {
        $applyPuskesmasOverride = $puskesmasId !== null && DataScope::isFullAccess($user);
        $asOf = $dateTo?->copy()->endOfDay();

        $scopedPatients = $this->patientQuery->scopedQuery($user);
        if ($applyPuskesmasOverride) {
            $scopedPatients->where('puskesmas_id', $puskesmasId);
        }

        $patientsPerRiskLevel = (clone $this->effectiveRiskClassifications($scopedPatients, $asOf))
            ->selectRaw('level, count(*) as total')
            ->groupBy('level')
            ->pluck('total', 'level');

        // 'patient_id' dikualifikasi eksplisit -- ambigu kalau $asOf diisi (joinSub 'latest_as_of'
        // juga punya kolom patient_id), aman/tidak berpengaruh kalau tidak (branch is_latest=true
        // tanpa join).
        $totalPatients = (clone $this->effectiveRiskClassifications($scopedPatients, $asOf))
            ->distinct('risk_classifications.patient_id')
            ->count('risk_classifications.patient_id');

        $scopedAssignments = $this->visitAssignmentService->scopedQuery($user);
        if ($applyPuskesmasOverride) {
            $scopedAssignments->where('puskesmas_id_snapshot', $puskesmasId);
        }
        if ($dateFrom !== null) {
            $scopedAssignments->whereDate('scheduled_date', '>=', $dateFrom->toDateString());
        }
        if ($dateTo !== null) {
            $scopedAssignments->whereDate('scheduled_date', '<=', $dateTo->toDateString());
        }

        $visitsPerStatus = $this->fill(
            self::VISIT_STATUSES,
            (clone $scopedAssignments)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        );

        $totalAssignments = (clone $scopedAssignments)->count();

        $scopedKaders = $this->scopedActiveKaders($user);
        if ($applyPuskesmasOverride) {
            $scopedKaders->where('puskesmas_id', $puskesmasId);
        }

        return new DashboardSummary(
            totalPatients: $totalPatients,
            patientsPerRiskLevel: $this->fill(self::RISK_LEVELS, $patientsPerRiskLevel),
            totalAssignments: $totalAssignments,
            visitsPerStatus: $visitsPerStatus,
            kaderAktifCount: (clone $scopedKaders)->count(),
            tingkatKepatuhan: $this->tingkatKepatuhan($totalAssignments, $visitsPerStatus['completed']),
            aktivitasHariIni: $this->aktivitasHariIni($scopedKaders, $dateFrom, $dateTo),
            risikoPerKecamatan: $this->risikoPerKecamatan($scopedPatients, $asOf),
            risikoPerDesa: $this->risikoPerDesa($scopedPatients, $asOf),
        );
    }

    /**
     * RiskClassification EFEKTIF per pasien pada satu titik waktu -- CURRENT (is_latest=true)
     * kalau $asOf null (perilaku default, sama seperti sebelum filter tanggal ada), atau
     * REKONSTRUKSI historis (baris computed_at TERBESAR yang masih <= $asOf per pasien) kalau
     * $asOf diisi. Dipakai bersama oleh patientsPerRiskLevel/totalPatients/risikoPerKecamatan/
     * risikoPerDesa supaya keempatnya konsisten "per tanggal yang sama".
     *
     * @param  Builder<PatientsCache>  $scopedPatients
     * @return Builder<RiskClassification>
     */
    private function effectiveRiskClassifications(Builder $scopedPatients, ?Carbon $asOf): Builder
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

        // TIDAK select('risk_classifications.*') di sini -- caller (patientsPerRiskLevel/
        // risikoPerKecamatan/dst) selalu pasang selectRaw()+groupBy() sendiri; mencampur '*' di
        // sini bikin GROUP BY tidak lengkap di bawah MySQL ONLY_FULL_GROUP_BY (default sejak
        // 5.7.5) begitu caller cuma group by 'level' atau 'kecamatan.id', bukan semua kolom.
        return RiskClassification::query()
            ->joinSub($latestPerPatient, 'latest_as_of', function ($join) {
                $join->on('risk_classifications.patient_id', '=', 'latest_as_of.patient_id')
                    ->on('risk_classifications.computed_at', '=', 'latest_as_of.max_computed_at');
            });
    }

    /**
     * @param  string[]  $keys
     * @param  Collection<string, int>  $counts
     * @return array<string, int>
     */
    private function fill(array $keys, Collection $counts): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = (int) ($counts[$key] ?? 0);
        }

        return $result;
    }

    private function tingkatKepatuhan(int $totalAssignments, int $completed): float
    {
        if ($totalAssignments === 0) {
            return 0.0;
        }

        return round(($completed / $totalAssignments) * 100, 2);
    }

    /**
     * Scope kader aktif sama persis dengan aturan puskesmas/kader-only dipakai di seluruh
     * dashboard (docs/planning/02 §7) -- SENGAJA bukan reuse KaderService::scopedQuery() begitu
     * saja, karena itu belum membatasi kader-only ke dirinya sendiri (dipakai utk kelola kader
     * oleh PJ/admin, bukan utk isolasi data pribadi kader), sementara dashboard yang lain semua
     * mengisolasi kader-only ke datanya sendiri -- harus konsisten.
     *
     * @return Builder<Kader>
     */
    private function scopedActiveKaders(User $user): Builder
    {
        if (DataScope::isFullAccess($user)) {
            return Kader::query()->where('status_aktif', true);
        }

        if (DataScope::isKaderOnly($user)) {
            $kader = $user->kader;

            return $kader !== null
                ? Kader::query()->where('id', $kader->id)->where('status_aktif', true)
                : Kader::query()->whereRaw('1 = 0');
        }

        return $user->puskesmas_id !== null
            ? Kader::query()->where('puskesmas_id', $user->puskesmas_id)->where('status_aktif', true)
            : Kader::query()->whereRaw('1 = 0');
    }

    /**
     * Per kader (dalam scope): target (semua assignment DALAM RANGE, apa pun statusnya), jumlah
     * yang sudah completed, dan waktu update terakhir DI ANTARA assignment dalam range itu --
     * kader tanpa assignment tetap muncul dengan target 0, supaya PJ bisa lihat siapa yang
     * kosong. Default (tanpa $dateFrom/$dateTo) TETAP "hari ini" sama seperti sebelum filter
     * tanggal ada -- nama field/JSON key sengaja tidak berubah (aktivitas_hari_ini) supaya
     * kontrak API stabil, cuma makna rentangnya yang jadi bisa diatur.
     *
     * @param  Builder<Kader>  $scopedKaders
     * @return array<int, array{kader_id: int, nama: ?string, target_hari_ini: int, selesai_hari_ini: int, last_update_at: ?string}>
     */
    private function aktivitasHariIni(Builder $scopedKaders, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $kaders = (clone $scopedKaders)->with('user')->get();

        if ($kaders->isEmpty()) {
            return [];
        }

        $rangeFrom = $dateFrom ?? Carbon::today();
        $rangeTo = $dateTo ?? Carbon::today();

        $todayByKader = VisitAssignment::query()
            ->whereIn('kader_id', $kaders->pluck('id'))
            ->whereDate('scheduled_date', '>=', $rangeFrom->toDateString())
            ->whereDate('scheduled_date', '<=', $rangeTo->toDateString())
            ->selectRaw('kader_id, count(*) as target, sum(case when status = \'completed\' then 1 else 0 end) as selesai, max(updated_at) as last_update')
            ->groupBy('kader_id')
            ->get()
            ->keyBy('kader_id');

        return $kaders->map(function (Kader $kader) use ($todayByKader) {
            $row = $todayByKader->get($kader->id);

            return [
                'kader_id' => $kader->id,
                'nama' => $kader->user?->name,
                'target_hari_ini' => (int) ($row->target ?? 0),
                'selesai_hari_ini' => (int) ($row->selesai ?? 0),
                'last_update_at' => $row?->last_update !== null ? Carbon::parse($row->last_update)->toIso8601String() : null,
            ];
        })->values()->all();
    }

    /**
     * Agregat jumlah pasien per level risiko, dikelompokkan per kecamatan (docs/planning/02
     * §13) -- BUKAN data poligon (itu sudah ada di frontend), cuma angka untuk di-mapping
     * berdasarkan nama/kode kecamatan. Lewat patients_cache.kecamatan_id LANGSUNG (BUKAN lagi
     * via desa_id -> desa.kecamatan_id) -- kecamatan bisa dikenali dari kecamatan_raw walau
     * desa-nya sendiri belum/tidak match (wilayah_status unknown/unresolved dengan kecamatan
     * tetap terisi, ~19,6% populasi per temuan gap wilayah, docs/planning/02 §2b). Rute lewat
     * desa dulu SEMPAT bikin populasi ini hilang total dari peta risiko kecamatan meski
     * kecamatan-nya sendiri tidak ambigu -- lihat migration add_kecamatan_id_to_patients_cache_table.
     *
     * @param  Builder<\App\Models\PatientsCache>  $scopedPatients
     * @return array<int, array{kecamatan_id: int, kecamatan_nama: string, kecamatan_kode: ?string, ringan: int, sedang: int, berat: int}>
     */
    private function risikoPerKecamatan(Builder $scopedPatients, ?Carbon $asOf): array
    {
        $rows = $this->effectiveRiskClassifications($scopedPatients, $asOf)
            ->join('patients_cache', 'patients_cache.id', '=', 'risk_classifications.patient_id')
            ->join('kecamatan', 'kecamatan.id', '=', 'patients_cache.kecamatan_id')
            ->selectRaw('kecamatan.id as kecamatan_id, kecamatan.nama as kecamatan_nama, kecamatan.kode_kemendagri as kecamatan_kode, risk_classifications.level as level, count(*) as total')
            ->groupBy('kecamatan.id', 'kecamatan.nama', 'kecamatan.kode_kemendagri', 'risk_classifications.level')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $kecamatanId = (int) $row->kecamatan_id;

            if (! isset($grouped[$kecamatanId])) {
                $grouped[$kecamatanId] = [
                    'kecamatan_id' => $kecamatanId,
                    'kecamatan_nama' => $row->kecamatan_nama,
                    'kecamatan_kode' => $row->kecamatan_kode,
                    'ringan' => 0,
                    'sedang' => 0,
                    'berat' => 0,
                ];
            }

            $grouped[$kecamatanId][$row->level] = (int) $row->total;
        }

        return array_values($grouped);
    }

    /**
     * Agregat jumlah pasien per level risiko, dikelompokkan per desa (docs/planning/02 §17) --
     * paralel risikoPerKecamatan() di atas, TAPI HANYA wilayah_status=resolved (BUKAN ikut
     * kecamatan_fallback seperti kecamatan -- level desa butuh presisi lebih tinggi, patokan
     * "kecamatan cuma py 1 puskesmas" tidak cukup buat nunjuk desa mana). Cakupan wajar kecil
     * (355 pasien resolved saat temuan gap wilayah sebelumnya) -- banyak desa tetap kosong
     * sampai kopipu:import-desa-puskesmas dijalankan penuh.
     *
     * @param  Builder<\App\Models\PatientsCache>  $scopedPatients
     * @return array<int, array{desa_id: int, desa_nama: string, desa_kode: ?string, ringan: int, sedang: int, berat: int}>
     */
    private function risikoPerDesa(Builder $scopedPatients, ?Carbon $asOf): array
    {
        $rows = $this->effectiveRiskClassifications($scopedPatients, $asOf)
            ->join('patients_cache', 'patients_cache.id', '=', 'risk_classifications.patient_id')
            ->where('patients_cache.wilayah_status', 'resolved')
            ->join('desa', 'desa.id', '=', 'patients_cache.desa_id')
            ->selectRaw('desa.id as desa_id, desa.nama as desa_nama, desa.kode_kemendagri as desa_kode, risk_classifications.level as level, count(*) as total')
            ->groupBy('desa.id', 'desa.nama', 'desa.kode_kemendagri', 'risk_classifications.level')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $desaId = (int) $row->desa_id;

            if (! isset($grouped[$desaId])) {
                $grouped[$desaId] = [
                    'desa_id' => $desaId,
                    'desa_nama' => $row->desa_nama,
                    'desa_kode' => $row->desa_kode,
                    'ringan' => 0,
                    'sedang' => 0,
                    'berat' => 0,
                ];
            }

            $grouped[$desaId][$row->level] = (int) $row->total;
        }

        return array_values($grouped);
    }
}
