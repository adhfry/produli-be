<?php

namespace App\Services\Patient;

use App\Models\PatientDailySnapshot;
use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Services\Risk\RiskClassificationHistoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kondisi pasien per tanggal (blueprint docs/planning/16 Fase 4, 2026-11-17).
 *
 * - buatHariIni(): snapshot harian setelah sinkron 02:00 (produli:snapshot-harian).
 * - klasifikasiPada(): klasifikasi risiko yang berlaku pada satu tanggal. Memakai snapshot bila
 *   tanggal itu sudah punya snapshot, selain itu direkonstruksi dari riwayat klasifikasi
 *   (RiskClassificationHistoryService, berdasar tanggal pemeriksaan). Keduanya memberi hasil
 *   yang sama untuk level risiko; snapshot menambah puskesmas pemilik pada hari itu.
 */
class PatientSnapshotService
{
    /** Urutan keparahan untuk menilai membaik/memburuk. */
    public const PERINGKAT = ['tidak_berisiko' => 0, 'ringan' => 1, 'sedang' => 2, 'berat' => 3];

    public function __construct(private readonly RiskClassificationHistoryService $riskHistory) {}

    /**
     * Satu baris per pasien untuk hari ini, dari kondisi TERKINI. Aman diulang di hari yang sama
     * (baris hari itu ditimpa dengan kondisi terbaru).
     *
     * @return int jumlah pasien yang dicatat
     */
    public function buatHariIni(): int
    {
        $hari = now()->toDateString();
        $total = 0;

        PatientsCache::query()
            ->with('latestRiskClassification:id,patient_id,level,early_detection_flag')
            ->withMax('labResults', 'tanggal_periksa')
            ->select(['id', 'puskesmas_id', 'kecamatan_id', 'desa_id', 'external_patient_id'])
            ->chunkById(1000, function ($pasien) use ($hari, &$total) {
                $baris = $pasien->map(fn (PatientsCache $p) => [
                    'snapshot_date' => $hari,
                    'patients_cache_id' => $p->id,
                    'puskesmas_id' => $p->puskesmas_id,
                    'kecamatan_id' => $p->kecamatan_id,
                    'desa_id' => $p->desa_id,
                    'risk_level' => $p->latestRiskClassification?->level,
                    'risk_classification_id' => $p->latestRiskClassification?->id,
                    'early_detection_flag' => (bool) $p->latestRiskClassification?->early_detection_flag,
                    'tanggal_lab_terakhir' => $p->lab_results_max_tanggal_periksa,
                    'created_at' => now(),
                ])->all();

                DB::table('patient_daily_snapshots')->upsert(
                    $baris,
                    ['snapshot_date', 'patients_cache_id'],
                    ['puskesmas_id', 'kecamatan_id', 'desa_id', 'risk_level', 'risk_classification_id', 'early_detection_flag', 'tanggal_lab_terakhir', 'created_at'],
                );
                $total += count($baris);
            });

        return $total;
    }

    public function adaSnapshot(Carbon $tanggal): bool
    {
        return PatientDailySnapshot::where('snapshot_date', $tanggal->toDateString())->exists();
    }

    /**
     * Klasifikasi risiko yang berlaku pada $tanggal untuk pasien-pasien ini.
     *
     * @param  iterable<int>  $patientIds
     * @return Collection<int, RiskClassification> kunci = patients_cache.id (pasien tanpa klasifikasi tidak ada)
     */
    public function klasifikasiPada(iterable $patientIds, Carbon $tanggal): Collection
    {
        $ids = collect($patientIds)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        if ($tanggal->isToday() || $tanggal->isFuture()) {
            return RiskClassification::whereIn('patient_id', $ids)->where('is_latest', true)->get()->keyBy('patient_id');
        }

        if ($this->adaSnapshot($tanggal)) {
            $idKlasifikasi = PatientDailySnapshot::where('snapshot_date', $tanggal->toDateString())
                ->whereIn('patients_cache_id', $ids)
                ->whereNotNull('risk_classification_id')
                ->pluck('risk_classification_id');

            return RiskClassification::whereIn('id', $idKlasifikasi)->get()->keyBy('patient_id');
        }

        return $this->riskHistory
            ->effectiveQuery(PatientsCache::query()->whereIn('id', $ids), $tanggal->copy()->endOfDay())
            ->select('risk_classifications.*')
            ->get()
            ->keyBy('patient_id');
    }

    /**
     * Arah perubahan dari $dulu ke $kini: membaik | memburuk | tetap, atau null bila salah satu
     * belum punya klasifikasi.
     */
    public static function perubahan(?string $dulu, ?string $kini): ?string
    {
        if ($dulu === null || $kini === null || ! isset(self::PERINGKAT[$dulu], self::PERINGKAT[$kini])) {
            return null;
        }

        return match (true) {
            self::PERINGKAT[$kini] < self::PERINGKAT[$dulu] => 'membaik',
            self::PERINGKAT[$kini] > self::PERINGKAT[$dulu] => 'memburuk',
            default => 'tetap',
        };
    }

    /**
     * Id pasien (dari query yang sudah difilter) yang perubahannya antara $dulu dan $kini sesuai
     * arah. Dipakai filter "Hanya yang membaik" di daftar pasien, sebelum paginasi.
     *
     * @param  Builder<PatientsCache>  $query
     * @return Collection<int, int>
     */
    public function idDenganPerubahan(Builder $query, Carbon $dulu, ?Carbon $kini, string $arah): Collection
    {
        $ids = (clone $query)->reorder()->pluck('patients_cache.id');
        $levelDulu = $this->klasifikasiPada($ids, $dulu)->map->level;
        $levelKini = $this->klasifikasiPada($ids, $kini ?? now())->map->level;

        return $ids->filter(fn ($id) => self::perubahan($levelDulu[$id] ?? null, $levelKini[$id] ?? null) === $arah)->values();
    }
}
