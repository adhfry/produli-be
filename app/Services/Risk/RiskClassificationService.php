<?php

namespace App\Services\Risk;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Models\RiskThreshold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hitung level risiko dari lab_results_cache x risk_thresholds, simpan snapshot versioned.
 * Lihat docs/planning/02-arsitektur-backend-produli.md §3.
 *
 * Kriteria (REVISI kedua, laporan bug A. JAZILI — pasien Trigliserida 192 SENDIRIAN yang
 * salah naik ke Sedang padahal Cholesterol/LDL/Urea semuanya normal/tidak ada) — lintas-
 * parameter, AND ketat utk kedua tier kombinasi, BUKAN "salah satu parameter saja cukup":
 * - Berat: kelima parameter di BERAT_PARAMETERS harus LENGKAP tersedia (hasil numerik untuk
 *   semuanya) DAN seluruhnya melebihi nilai rujukan sekaligus. Sengaja BUKAN "yang tersedia
 *   melebihi" secara longgar — kalau cuma Gula Darah Puasa yang pernah diperiksa (parameter
 *   lain belum ada hasil), itu TIDAK BOLEH otomatis jadi Berat.
 * - Sedang: pola IDENTIK dengan Berat, cuma bedanya set parameter — keempat parameter di
 *   SEDANG_PARAMETERS harus LENGKAP tersedia DAN seluruhnya melebihi nilai rujukan sekaligus.
 *   SEBELUMNYA cukup "salah satu dari 4 parameter melebihi" (OR) — itu yang menyebabkan bug
 *   A. Jazili: Trigliserida 192 sendirian (Cholesterol/LDL normal, Gula Darah Puasa tidak
 *   diperiksa) salah dianggap Sedang.
 * - Ringan (REVISI KETIGA, dikembalikan sesuai standar resmi landing page "Pemeriksaan & Nilai
 *   Rujukan"): KHUSUS kalau Gula Darah Puasa TERSEDIA dan melebihi ambang, TAPI kombinasi
 *   Sedang/Berat di atas tidak terpenuhi — BUKAN "parameter apa pun sendirian", cuma Gula Darah
 *   Puasa yang punya tier Ringan berdiri sendiri (mis. Trigliserida 192 sendirian TETAP tidak
 *   menghasilkan apa pun, bug A. Jazili di atas tidak kembali). Diperiksa PALING TERAKHIR
 *   (setelah Sedang/Berat gagal), jadi kalau GDP kebetulan juga bagian dari kombinasi Sedang/
 *   Berat yang lengkap, level yang lebih parah itu yang menang lewat determineLevel() ini
 *   sendiri, bukan Ringan.
 *
 * REVISI Bu Kadis: Creatinine BUKAN lagi bagian BERAT_PARAMETERS/SEDANG_PARAMETERS (tidak
 * dobel-hitung) — dipindah jadi "direct classifier" (RiskThreshold.is_direct_classifier=true).
 * Parameter direct-classifier bisa langsung menentukan level pasien SENDIRIAN lewat threshold
 * bertingkat (Creatinine: 1.7-1.9=sedang, >=2.0=berat), tanpa perlu parameter lain ikut
 * melebihi. Level akhir = level TERPARAH antara jalur 5-parameter di atas dan jalur
 * direct-classifier manapun yang match (urutan keparahan lihat SEVERITY_ORDER).
 *
 * REVISI Bu Kadis juga menambah tier 'tidak_berisiko': kalau tidak ada satu pun jalur di atas
 * yang match (levelnya null) TAPI pasien punya baris is_latest sebelumnya (pernah divonis
 * sesuatu), berarti pasien sudah membaik total — ditulis baris baru level 'tidak_berisiko'
 * (bukan dibiarkan diam-diam tetap di level lama selamanya).
 *
 * Nama parameter di BERAT_PARAMETERS/SEDANG_PARAMETERS HARUS persis sama dengan kolom
 * lab_results_cache.parameter dari SiLAKES asli (dikonfirmasi dari data sync nyata) — bukan
 * singkatan medis umum. "GDP" (singkatan) TIDAK PERNAH muncul di data asli, yang ada adalah
 * "Gula Darah Puasa" -- salah satu bug yang pernah bikin patients_classified selalu 0.
 */
class RiskClassificationService
{
    private const BERAT_PARAMETERS = ['Gula Darah Puasa', 'Cholesterol', 'Trigliserida', 'LDL', 'Urea'];

    private const SEDANG_PARAMETERS = ['Gula Darah Puasa', 'Cholesterol', 'Trigliserida', 'LDL'];

    /** Urutan keparahan dari yang paling ringan ke paling parah, dipakai buat ambil level terparah. */
    private const SEVERITY_ORDER = ['tidak_berisiko', 'ringan', 'sedang', 'berat'];

    /**
     * Hitung ulang klasifikasi risiko pasien dari hasil lab terbaru per parameter.
     * Tidak melempar exception untuk nilai non-numerik — parameter itu di-skip + di-log
     * (docs/planning/01 §Catatan Implementasi Wajib), tidak menggagalkan proses sync.
     *
     * Return null (tidak menulis baris baru) HANYA kalau tidak ada level yang match DAN
     * pasien memang belum pernah punya klasifikasi sebelumnya (belum relevan sama sekali).
     * Kalau pasien PERNAH punya klasifikasi (is_latest=true) tapi sekarang tidak ada
     * parameter yang melebihi rujukan sama sekali, itu artinya pasien membaik total —
     * ditulis baris baru level 'tidak_berisiko', bukan dibiarkan diam di level lama.
     */
    public function classify(PatientsCache $patient): ?RiskClassification
    {
        // Urutkan berdasar tanggal pemeriksaan medis (bukan urutan/waktu sync) — delta sync
        // tidak menjamin urutan kronologis, hasil lama bisa tersinkron belakangan (docs/planning/02 §3).
        // synced_at sebagai tiebreak kalau tanggal_periksa sama persis (retest di hari yang sama).
        $latestResultPerParameter = LabResultCache::where('patient_id', $patient->external_patient_id)
            ->orderByDesc('tanggal_periksa')
            ->orderByDesc('synced_at')
            ->get()
            ->unique('parameter');

        $thresholds = RiskThreshold::where('is_active', true)->get()->groupBy('parameter');

        $criteria = [];
        $availableParameters = [];
        $exceededParameters = [];
        $directClassifierLevels = [];

        foreach ($latestResultPerParameter as $result) {
            if (! is_numeric($result->value)) {
                Log::warning('RiskClassificationService: nilai hasil lab non-numerik, parameter dilewati', [
                    'patient_id' => $patient->external_patient_id,
                    'parameter' => $result->parameter,
                    'value' => $result->value,
                ]);

                continue;
            }

            $availableParameters[] = $result->parameter;
            $value = (float) $result->value;
            $exceeded = false;

            foreach ($thresholds->get($result->parameter, collect()) as $threshold) {
                if (! $this->matchesThreshold($value, $threshold)) {
                    continue;
                }

                $exceeded = true;

                $criteria[] = [
                    'parameter' => $result->parameter,
                    'value' => $value,
                    'tanggal_periksa' => $result->tanggal_periksa?->toDateString(),
                    'operator' => $threshold->operator,
                    'threshold_min' => $threshold->threshold_min,
                    'threshold_max' => $threshold->threshold_max,
                    'level' => $threshold->level,
                    'is_direct_classifier' => $threshold->is_direct_classifier,
                ];

                if ($threshold->is_direct_classifier) {
                    $directClassifierLevels[] = $threshold->level;
                }
            }

            if ($exceeded) {
                $exceededParameters[] = $result->parameter;
            }
        }

        $comboLevel = $this->determineLevel($availableParameters, $exceededParameters);
        $matchedLevel = $this->mostSevere([$comboLevel, ...$directClassifierLevels]);

        if ($matchedLevel === null) {
            $pernahDiklasifikasi = RiskClassification::where('patient_id', $patient->id)
                ->where('is_latest', true)
                ->exists();

            if (! $pernahDiklasifikasi) {
                return null;
            }

            $matchedLevel = 'tidak_berisiko';
        }

        [$earlyDetectionFlag, $earlyDetectionReason] = $this->evaluateEarlyDetection($patient, $matchedLevel, $criteria, $exceededParameters);

        // Idempotensi -- classify() dipanggil TIAP KALI SyncSilakesService melihat "ada data
        // baru" untuk pasien ini, TAPI "ada data baru" cuma berarti baris lab_results_cache-nya
        // muncul di window delta sync (bisa saja SiLAKES bump updated_at tanpa nilai berubah
        // sama sekali), DITAMBAH pemanggilan manual produli:reclassify-risk yang selalu
        // mengevaluasi ULANG SEMUA pasien tanpa syarat. Tanpa guard ini, setiap panggilan yang
        // hasilnya PERSIS SAMA dengan is_latest sekarang tetap menulis baris baru -- bug nyata
        // yang bikin "Riwayat & Tren Kondisi" penuh baris duplikat (level+parameter+nilai sama
        // persis, cuma computed_at beda) tanpa ada perubahan kondisi pasien sungguhan.
        $current = RiskClassification::where('patient_id', $patient->id)
            ->where('is_latest', true)
            ->first();

        if ($current !== null && $current->level === $matchedLevel && $this->criteriaEquivalent($current->criteria_snapshot, $criteria)) {
            return null;
        }

        return DB::transaction(function () use ($patient, $matchedLevel, $criteria, $earlyDetectionFlag, $earlyDetectionReason) {
            RiskClassification::where('patient_id', $patient->id)
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            return RiskClassification::create([
                'patient_id' => $patient->id,
                'level' => $matchedLevel,
                'criteria_snapshot' => $criteria,
                'computed_at' => now(),
                'is_latest' => true,
                'early_detection_flag' => $earlyDetectionFlag,
                'early_detection_reason' => $earlyDetectionReason,
            ]);
        });
    }

    /**
     * "Smart Early Detection" (revisi Bu Kadis) -- rule-based (bukan ML, dikonfirmasi user),
     * cuma dihitung untuk pasien yang level akhirnya SEDANG. Tiga sinyal independen (salah satu
     * SAJA sudah cukup untuk flag=true -- termasuk kasus GABUNGAN: kombo cuma "rata-rata sedang"
     * TAPI Creatinine sendiri hampir menyentuh 2 tetap ke-flag lewat sinyal proximity):
     *
     * 1. Proximity Creatinine (direct-classifier) ke ambang Berat berikutnya: posisi nilai di
     *    dalam band [threshold_min, tier 'berat' berikutnya) sebagai persentase -- mis.
     *    Creatinine 1.85 di band 1.7-1.9 dengan tier Berat mulai 2.0 -> (1.85-1.7)/(2.0-1.7) =
     *    50%. Di atas PRODULI_EARLY_DETECTION_PROXIMITY_THRESHOLD (default 60%) -> flag,
     *    berdasarkan berapa persen Creatinine sudah menuju ambang Berat SENDIRIAN (1 parameter
     *    penting yang tetap dinilai terpisah dari kombo, tidak pernah dilonggarkan).
     * 2. Margin kombo (5 BERAT_PARAMETERS): TIDAK cukup sekadar "N dari 5 exceeded" (jumlah)
     *    seperti sebelumnya -- itu bisa ke-flag cuma dari 1 parameter yang jauh di atas ambang
     *    ditambah beberapa yang CUMA SEDIKIT di atas, padahal belum tentu benar-benar "menuju
     *    Berat" sebagai satu kesatuan. Sekarang tiap parameter kombo yang exceeded dihitung
     *    margin-nya ((nilai-ambang)/ambang, persen DI ATAS nilai rujukan aslinya), dan HANYA
     *    di-flag kalau (a) minimal combo_min_parameters parameter exceeded BERSAMAAN, (b)
     *    SELURUH parameter combo_required_parameters (default: Gula Darah Puasa, LDL,
     *    Trigliserida -- pemeriksaan terpenting secara klinis) WAJIB termasuk yang exceeded itu
     *    (Cholesterol/Urea cuma pelengkap opsional, TIDAK bisa menggantikan salah satu dari 3
     *    wajib), DAN (c) SELURUH parameter yang exceeded itu (bukan cuma rata-ratanya, tapi
     *    tiap satu) >= combo_margin_threshold_percent di atas rujukan.
     * 3. Tren memburuk 3 pemeriksaan berturut-turut: nilai parameter yang sama naik terus
     *    (current > sebelumnya > sebelumnya lagi) di 2 klasifikasi historis + klasifikasi ini.
     *
     * @param  array<int, array<string, mixed>>  $criteria
     * @param  array<int, string>  $exceededParameters
     * @return array{0: bool, 1: ?array<string, mixed>}
     */
    private function evaluateEarlyDetection(PatientsCache $patient, ?string $matchedLevel, array $criteria, array $exceededParameters): array
    {
        if ($matchedLevel !== 'sedang') {
            return [false, null];
        }

        $reasons = [];

        foreach ($criteria as $c) {
            if (! ($c['is_direct_classifier'] ?? false) || $c['level'] !== 'sedang') {
                continue;
            }

            $nextTier = RiskThreshold::where('parameter', $c['parameter'])
                ->where('is_direct_classifier', true)
                ->where('level', 'berat')
                ->where('is_active', true)
                ->first();

            if ($nextTier === null || $nextTier->threshold_min === null || $c['threshold_min'] === null) {
                continue;
            }

            $bandWidth = $nextTier->threshold_min - $c['threshold_min'];

            if ($bandWidth <= 0) {
                continue;
            }

            $proximity = ($c['value'] - $c['threshold_min']) / $bandWidth;
            $threshold = (float) config('produli.early_detection.proximity_threshold', 0.6);

            if ($proximity >= $threshold) {
                $reasons[] = [
                    'type' => 'proximity',
                    'parameter' => $c['parameter'],
                    'value' => $c['value'],
                    'next_tier_threshold' => $nextTier->threshold_min,
                    'proximity_percent' => round($proximity * 100),
                    'message' => "{$c['parameter']} ({$c['value']}) mendekati ambang Berat ({$nextTier->threshold_min}) -- ".round($proximity * 100).'% menuju tier berikutnya.',
                ];
            }
        }

        foreach ($this->evaluateComboMargin($criteria, $exceededParameters) as $reason) {
            $reasons[] = $reason;
        }

        foreach ($this->detectWorseningTrend($patient, $criteria) as $trend) {
            $reasons[] = $trend;
        }

        if ($reasons === []) {
            return [false, null];
        }

        return [true, $reasons];
    }

    /**
     * Sinyal combo_breadth berbasis MARGIN persentase (revisi Bu Kadis), bukan lagi sekadar
     * jumlah parameter yang exceeded -- lihat docblock evaluateEarlyDetection() untuk alasan
     * lengkap. Margin per parameter = (nilai - ambang) / ambang, dinyatakan persen DI ATAS
     * nilai rujukan aslinya (mis. nilai 180 dgn ambang 120 -> margin 50%).
     *
     * @param  array<int, array<string, mixed>>  $criteria
     * @param  array<int, string>  $exceededParameters
     * @return array<int, array<string, mixed>>
     */
    private function evaluateComboMargin(array $criteria, array $exceededParameters): array
    {
        $margins = [];

        foreach ($criteria as $c) {
            if (($c['is_direct_classifier'] ?? false) || ! in_array($c['parameter'], self::BERAT_PARAMETERS, true)) {
                continue;
            }

            if ($c['threshold_min'] === null || (float) $c['threshold_min'] <= 0) {
                continue;
            }

            $margins[$c['parameter']] = (($c['value'] - $c['threshold_min']) / $c['threshold_min']) * 100;
        }

        $minParameters = (int) config('produli.early_detection.combo_min_parameters', 3);
        $marginThreshold = (float) config('produli.early_detection.combo_margin_threshold_percent', 50);
        $requiredParameters = (array) config('produli.early_detection.combo_required_parameters', []);

        // "Bukan hanya 1 parameter" -- 1 parameter yang jauh di atas ambang sendirian TIDAK
        // cukup, harus beberapa parameter BERSAMAAN yang sama-sama tinggi.
        if (count($margins) < $minParameters) {
            return [];
        }

        // Keputusan klinis: GDP/LDL/Trigliserida (default combo_required_parameters) WAJIB
        // ikut exceeded -- kombinasi Cholesterol+Urea+1 lainnya TIDAK cukup meski jumlahnya
        // sudah >= combo_min_parameters, karena ketiganya dianggap pemeriksaan terpenting utk
        // menilai "trending ke Berat" secara klinis (bukan sekadar count generik).
        if (array_diff($requiredParameters, array_keys($margins)) !== []) {
            return [];
        }

        // SELURUH parameter yang exceeded (bukan cuma rata-ratanya) harus >= ambang margin --
        // 1 parameter yang cuma sedikit di atas rujukan menggagalkan seluruh sinyal, supaya
        // tidak ke-flag dari campuran "1 tinggi + beberapa nyaris ambang" seperti bug sebelumnya.
        if (min($margins) < $marginThreshold) {
            return [];
        }

        $comboMissing = array_values(array_diff(self::BERAT_PARAMETERS, array_keys($margins)));

        if ($comboMissing === []) {
            // Seluruh 5 parameter exceeded = sudah Berat lewat determineLevel(), bukan lagi
            // "menuju" Berat -- evaluateEarlyDetection() sendiri cuma jalan utk matchedLevel=
            // 'sedang', jadi baris ini secara matematis tidak pernah tercapai, dijaga eksplisit
            // supaya asumsi ini tidak diam-diam salah kalau determineLevel() berubah nanti.
            return [];
        }

        $averageMargin = array_sum($margins) / count($margins);

        return [[
            'type' => 'combo_breadth',
            'exceeded_count' => count($margins),
            'total_count' => count(self::BERAT_PARAMETERS),
            'missing_parameters' => $comboMissing,
            'average_margin_percent' => round($averageMargin),
            'parameter_margins_percent' => array_map(fn (float $m) => round($m), $margins),
            'message' => count($margins).' dari '.count(self::BERAT_PARAMETERS)
                .' parameter kombinasi sudah melebihi ambang, rata-rata '.round($averageMargin)
                .'% di atas nilai rujukan -- tinggal '.implode(', ', $comboMissing).' untuk jadi Berat.',
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $currentCriteria
     * @return array<int, array<string, mixed>>
     */
    private function detectWorseningTrend(PatientsCache $patient, array $currentCriteria): array
    {
        // TIDAK filter is_latest=true -- flip-on-write classify() cuma menyisakan SATU baris
        // is_latest=true per pasien di titik manapun, jadi filter itu tidak akan pernah
        // mengembalikan 2 baris. Ambil 2 baris TERBARU apa adanya (computed_at, id sebagai
        // tiebreak karena presisi datetime SQLite cuma per detik).
        $history = RiskClassification::where('patient_id', $patient->id)
            ->orderByDesc('computed_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        if ($history->count() < 2) {
            return [];
        }

        $trends = [];

        foreach ($currentCriteria as $c) {
            $values = [(float) $c['value']];
            $matchFailed = false;

            foreach ($history as $h) {
                $match = collect($h->criteria_snapshot)->firstWhere('parameter', $c['parameter']);

                if ($match === null || ! isset($match['value'])) {
                    $matchFailed = true;

                    break;
                }

                $values[] = (float) $match['value'];
            }

            if ($matchFailed || count($values) < 3) {
                continue;
            }

            if ($values[0] > $values[1] && $values[1] > $values[2]) {
                $trends[] = [
                    'type' => 'worsening_trend',
                    'parameter' => $c['parameter'],
                    'values_terbaru_ke_lama' => $values,
                    'message' => "{$c['parameter']} cenderung memburuk 3 pemeriksaan terakhir berturut-turut ({$values[2]} -> {$values[1]} -> {$values[0]}).",
                ];
            }
        }

        return $trends;
    }

    /**
     * Bandingkan criteria_snapshot TERSIMPAN (dari DB, hasil json_decode) dengan yang BARU
     * dihitung -- dipakai guard idempotensi di classify() di atas. Sengaja HANYA membandingkan
     * parameter/value/tanggal_periksa/level per baris (bukan seluruh field) -- operator/
     * threshold_min/threshold_max/is_direct_classifier semuanya deterministik dari kombinasi
     * (parameter, level) terhadap risk_thresholds aktif saat ini, jadi kalau parameter+level
     * sama berarti field-field itu otomatis sama juga (kecuali admin mengubah threshold di
     * antara 2 panggilan, kasus langka yang justru MEMANG ingin memicu klasifikasi ulang --
     * tapi itu di luar cakupan guard ini, akan tertangkap lewat re-run manual berikutnya).
     *
     * @param  array<int, array<string, mixed>>  $stored
     * @param  array<int, array<string, mixed>>  $fresh
     */
    private function criteriaEquivalent(array $stored, array $fresh): bool
    {
        if (count($stored) !== count($fresh)) {
            return false;
        }

        foreach ($stored as $i => $storedRow) {
            $freshRow = $fresh[$i] ?? null;

            if ($freshRow === null
                || $storedRow['parameter'] !== $freshRow['parameter']
                || (float) $storedRow['value'] !== (float) $freshRow['value']
                || $storedRow['tanggal_periksa'] !== $freshRow['tanggal_periksa']
                || $storedRow['level'] !== $freshRow['level']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $availableParameters  Parameter dengan hasil lab numerik (apapun nilainya).
     * @param  array<int, string>  $exceededParameters  Subset yang melebihi nilai rujukan.
     */
    private function determineLevel(array $availableParameters, array $exceededParameters): ?string
    {
        $beratLengkap = array_diff(self::BERAT_PARAMETERS, $availableParameters) === [];
        $beratSemuaMelebihi = array_diff(self::BERAT_PARAMETERS, $exceededParameters) === [];

        if ($beratLengkap && $beratSemuaMelebihi) {
            return 'berat';
        }

        // AND ketat, pola IDENTIK dengan Berat di atas (bukan lagi "salah satu dari
        // SEDANG_PARAMETERS melebihi" -- itu bug A. Jazili, lihat docblock kelas). Kombinasi
        // parsial (mis. cuma Trigliserida sendirian) TIDAK cukup -- jatuh ke pengecekan Ringan
        // di bawah (yang cuma peduli Gula Darah Puasa), lalu null/'tidak_berisiko' kalau itu pun
        // tidak match.
        $sedangLengkap = array_diff(self::SEDANG_PARAMETERS, $availableParameters) === [];
        $sedangSemuaMelebihi = array_diff(self::SEDANG_PARAMETERS, $exceededParameters) === [];

        if ($sedangLengkap && $sedangSemuaMelebihi) {
            return 'sedang';
        }

        // Ringan (lihat docblock kelas, REVISI KETIGA) -- KHUSUS Gula Darah Puasa, bukan
        // parameter kombinasi apa pun. Dicek PALING TERAKHIR (Berat/Sedang sudah gagal di atas),
        // supaya tidak menang-menangan sendiri terhadap kombinasi yang lebih parah.
        if (in_array('Gula Darah Puasa', $exceededParameters, true)) {
            return 'ringan';
        }

        return null;
    }

    /**
     * @param  array<int, ?string>  $levels
     */
    private function mostSevere(array $levels): ?string
    {
        $best = null;
        $bestRank = -1;

        foreach ($levels as $level) {
            if ($level === null) {
                continue;
            }

            $rank = array_search($level, self::SEVERITY_ORDER, true);

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $level;
            }
        }

        return $best;
    }

    private function matchesThreshold(float $value, RiskThreshold $threshold): bool
    {
        return match ($threshold->operator) {
            '>' => $value > $threshold->threshold_min,
            '>=' => $value >= $threshold->threshold_min,
            '<' => $value < $threshold->threshold_min,
            '<=' => $value <= $threshold->threshold_min,
            'between' => $value >= $threshold->threshold_min && $value <= $threshold->threshold_max,
            default => false,
        };
    }
}
