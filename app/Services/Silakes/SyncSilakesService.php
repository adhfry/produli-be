<?php

namespace App\Services\Silakes;

use App\Models\IntegrationSyncLog;
use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\ReferenceRangeCache;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use App\Services\Risk\RiskClassificationService;
use App\Services\Wilayah\WilayahResolver;
use Carbon\Carbon;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Pull terjadwal dari SiLAKES -> map ke cache lokal -> trigger RiskClassificationService
 * untuk pasien yang datanya baru. Lihat docs/planning/02 §3 dan docs/planning/04.
 *
 * Endpoint 1-3 (patients, lab-results, master-wilayah) READ-ONLY MUTLAK — service ini
 * tidak pernah menulis balik ke SiLAKES (itu tugas VisitReportService via endpoint 4).
 */
class SyncSilakesService
{
    private const PER_PAGE = 200;

    // Rate limit SiLAKES: 60 req/menit (docs/planning/04 §3). PER_PAGE sudah di batas maksimal
    // yang diizinkan endpoint (bukan yang bisa dinaikkan lagi) -- jeda antar-halaman inilah yang
    // menjaga laju di bawah limit untuk dataset besar (ribuan pasien -> puluhan halaman
    // berturut-turut). 1200ms/halaman ~= maks 50 req/menit, sisa headroom untuk panggilan lain
    // (mis. MasterWilayahSeeder) yang mungkin jalan berdekatan.
    private const PAGE_DELAY_MS = 1200;

    public function __construct(
        private readonly SilakesApiClient $client,
        private readonly WilayahResolver $wilayahResolver,
        private readonly RiskClassificationService $riskClassificationService,
        private readonly NotifyService $notifyService,
    ) {}

    /**
     * @return array{patients_synced: int, lab_results_synced: int, patients_classified: int, reference_ranges_synced: int}
     */
    public function run(): array
    {
        // Lab results DULUAN, baru patients — syncPatients() butuh lab_results_cache yang
        // sudah mutakhir untuk menggerbang kelayakan (lihat isEligible()). Urutan sebaliknya
        // berarti pasien baru selalu dievaluasi dari data lab yang seangkatan lebih lama.
        //
        // syncReferenceRanges() WAJIB sebelum classifyPatients() -- RiskClassificationService
        // sekarang baca reference_ranges_cache untuk 5 dari 6 parameter (lihat
        // SilakesReferenceRangeService), kalau classify() jalan duluan dia akan pakai cache
        // yang basi dari run sebelumnya untuk data yang baru saja masuk di run ini.
        //
        // Klasifikasi sendiri baru bisa dilakukan SETELAH syncPatients() -- untuk pasien
        // yang benar-benar baru (belum pernah ada baris patients_cache sama sekali), baris itu
        // belum ada saat syncLabResults() selesai (dibuat syncPatients() SETELAHNYA), jadi
        // classify() di sana selalu dilewati senyap (patient null) untuk sinkronisasi pertama
        // mereka -- bug nyata yang ketahuan lewat regresi test, bukan cuma test basi. Makanya
        // syncLabResults() cuma MENGUMPULKAN id pasien yang datanya baru, klasifikasi
        // sungguhannya dieksekusi di sini, di bawah syncPatients().
        [$labResultsSynced, $externalPatientIdsWithNewData] = $this->syncLabResults();
        $patientsSynced = $this->syncPatients();
        $referenceRangesSynced = $this->syncReferenceRanges();
        $patientsClassified = $this->classifyPatients($externalPatientIdsWithNewData);

        return [
            'patients_synced' => $patientsSynced,
            'lab_results_synced' => $labResultsSynced,
            'patients_classified' => $patientsClassified,
            'reference_ranges_synced' => $referenceRangesSynced,
        ];
    }

    /**
     * run() + catat hasilnya ke integration_sync_logs -- SATU-SATUNYA tempat yang menulis log
     * ini, dipakai bersama oleh produli:sync-silakes (cron, throttle 48 jam) dan
     * SilakesSyncController (tombol "Sinkronisasi SiLAKES" di sidebar, super_admin, TANPA
     * throttle -- klik manual berarti operator secara eksplisit minta sync sekarang). Supaya
     * kedua jalur ini selalu mencatat log yang identik, bukan dua salinan logika yang bisa
     * drift beda cara pencatatannya.
     *
     * @return array{patients_synced: int, lab_results_synced: int, patients_classified: int}
     *
     * @throws Throwable dilempar ulang setelah dicatat sebagai 'failed' -- pemanggil yang
     *                    menentukan bagaimana meresponnya (CLI exit code vs HTTP error response).
     */
    public function runAndLog(): array
    {
        $requestedAt = now();

        try {
            $result = $this->run();

            IntegrationSyncLog::create([
                'service_name' => 'SyncSilakesService',
                'endpoint' => 'patients+lab-results',
                'requested_at' => $requestedAt,
                'status' => 'success',
                'records_count' => $result['patients_synced'] + $result['lab_results_synced'],
                'details' => $result,
            ]);

            // Broadcast (revisi Bu Kadis) -- 'push' saja (in-app), bukan email/WA ke SEMUA user
            // tiap sync (jalan dailyAt('02:00') + tiap klik manual, terlalu sering untuk kanal
            // yang lebih intrusif). Cuma untuk sync yang BENAR-BENAR sukses (bukan branch catch)
            // DAN benar-benar ada perubahan -- sync yang tidak menemukan apa pun (0 pasien, 0
            // hasil lab, mis. dipanggil manual berkali-kali berturut-turut tanpa data baru di
            // SiLAKES) TIDAK PERNAH bikin notifikasi "Sinkronisasi Selesai" kosong, keluhan nyata
            // user: notifikasi berulang padahal tidak ada yang disinkron.
            if ($result['patients_synced'] > 0 || $result['lab_results_synced'] > 0) {
                $this->notifyService->notify(
                    NotifiableTarget::broadcast(),
                    new NotificationPayload(
                        type: 'silakes_sync_completed',
                        title: 'Sinkronisasi SiLAKES Selesai',
                        body: "{$result['patients_synced']} pasien, {$result['lab_results_synced']} hasil lab tersinkron.",
                        data: array_merge(['type' => 'silakes_sync_completed'], $result),
                    ),
                    ['push'],
                );
            }

            return $result;
        } catch (Throwable $e) {
            IntegrationSyncLog::create([
                'service_name' => 'SyncSilakesService',
                'endpoint' => 'patients+lab-results',
                'requested_at' => $requestedAt,
                'status' => 'failed',
                'records_count' => 0,
                'details' => ['error' => $e->getMessage()],
            ]);

            throw $e;
        }
    }

    /**
     * Hanya menarik pasien Prolanis — filter is_prolanis=1 dipaksa di SilakesApiClient::patients(),
     * bukan di sini, supaya berlaku untuk pemanggil mana pun (mandat PRODULI, bukan pilihan sync ini saja).
     *
     * Pasien is_prolanis=1 TAPI tidak punya riwayat surat_hasil_labs final (completed+approved+
     * is_kunjungan_prolanis) sejak produli.silakes.lab_results_since DILEWATI (tidak di-upsert) --
     * belum relevan untuk PRODULI (lihat isEligible()). Kalau sebelumnya sempat ter-cache lalu jadi
     * tidak eligible lagi (mis. cutoff berubah), baris lama TIDAK otomatis dihapus di sini --
     * lihat produli:prune-ineligible-patients untuk pembersihan eksplisit.
     */
    public function syncPatients(): int
    {
        $since = $this->toIso8601(PatientsCache::max('last_synced_at'));
        $cursor = null;
        $count = 0;

        do {
            $body = $this->client->patients(array_filter([
                'since' => $since,
                'cursor' => $cursor,
                'per_page' => self::PER_PAGE,
            ]));

            foreach ($body['data'] ?? [] as $row) {
                if ($this->isEligible($row['patient_id'])) {
                    $this->upsertPatient($row);
                    $count++;
                }
            }

            $cursor = $body['meta']['next_cursor'] ?? null;
            $hasMore = (bool) ($body['meta']['has_more'] ?? false);

            if ($hasMore && $cursor) {
                Sleep::for(self::PAGE_DELAY_MS)->milliseconds();
            }
        } while ($hasMore && $cursor);

        return $count;
    }

    /**
     * Eligible = punya minimal 1 baris lab_results_cache -- kolom itu sendiri sudah difilter ke
     * tanggal_periksa >= cutoff saat upsert (lihat upsertLabResultParameter()), jadi cukup cek
     * keberadaannya, tidak perlu ulang syarat tanggal di sini.
     */
    private function isEligible(int $externalPatientId): bool
    {
        return LabResultCache::where('patient_id', $externalPatientId)->exists();
    }

    /**
     * @return array{0: int, 1: array<int, true>} [lab_results_synced, external_patient_id => true pasien yang datanya baru]
     */
    public function syncLabResults(): array
    {
        $since = $this->toIso8601(LabResultCache::max('synced_at'));
        $sinceDateCutoff = (string) config('produli.silakes.lab_results_since');
        $cursor = null;
        $count = 0;
        $externalPatientIdsWithNewData = [];

        do {
            $body = $this->client->labResults(array_filter([
                'since' => $since,
                'cursor' => $cursor,
                'per_page' => self::PER_PAGE,
            ]));

            foreach ($body['data'] ?? [] as $labResult) {
                // Business cutoff PRODULI (bukan syarat SiLAKES) — hasil lab lebih tua dari ini
                // tidak relevan untuk ambang batas risiko terkini, jangan ikut di-cache.
                if (($labResult['tanggal'] ?? null) === null || $labResult['tanggal'] < $sinceDateCutoff) {
                    continue;
                }

                foreach ($labResult['parameters'] ?? [] as $parameter) {
                    $this->upsertLabResultParameter($labResult, $parameter);
                    $count++;
                }

                $externalPatientIdsWithNewData[$labResult['patient_id']] = true;
            }

            $cursor = $body['meta']['next_cursor'] ?? null;
            $hasMore = (bool) ($body['meta']['has_more'] ?? false);

            if ($hasMore && $cursor) {
                Sleep::for(self::PAGE_DELAY_MS)->milliseconds();
            }
        } while ($hasMore && $cursor);

        return [$count, $externalPatientIdsWithNewData];
    }

    /**
     * Tarik SELURUH nilai rujukan terstruktur SiLAKES (satu kali fetch penuh, TIDAK
     * dipaginasi -- dataset kecil, ~143 baris) lalu truncate+reinsert reference_ranges_cache.
     * Idempotent by design (bukan delta/upsert seperti syncPatients()/syncLabResults() --
     * data ini statis dan kecil, refresh penuh tiap run jauh lebih sederhana daripada delta
     * sync dan tetap murah, cuma 1 panggilan API per run).
     */
    public function syncReferenceRanges(): int
    {
        $body = $this->client->referenceRanges();
        $rangesByParameterKey = $body['data']['ranges'] ?? [];

        $now = Carbon::now();
        $rows = [];

        foreach ($rangesByParameterKey as $parameterKey => $bands) {
            foreach ($bands as $band) {
                $rows[] = [
                    'parameter_key' => $parameterKey,
                    'gender' => $band['gender'] ?? null,
                    'age_min_years' => $band['age_min_years'] ?? null,
                    'age_max_years' => $band['age_max_years'] ?? null,
                    'age_min_days' => $band['age_min_days'] ?? null,
                    'age_max_days' => $band['age_max_days'] ?? null,
                    'value_min' => $band['value_min'] ?? null,
                    'value_max' => $band['value_max'] ?? null,
                    'min_inclusive' => $band['min_inclusive'] ?? true,
                    'max_inclusive' => $band['max_inclusive'] ?? true,
                    'category' => $band['category'],
                    'category_label' => $band['category_label'],
                    'severity_rank' => $band['severity_rank'] ?? 0,
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        ReferenceRangeCache::query()->truncate();
        foreach (array_chunk($rows, 200) as $chunk) {
            ReferenceRangeCache::insert($chunk);
        }

        return count($rows);
    }

    /**
     * Dipanggil dari run() SETELAH syncPatients() -- lihat komentar run() untuk alasan urutan
     * ini wajib (pasien baru belum punya baris patients_cache saat syncLabResults() selesai).
     *
     * @param  array<int, true>  $externalPatientIdsWithNewData
     */
    private function classifyPatients(array $externalPatientIdsWithNewData): int
    {
        $classifiedCount = 0;

        foreach (array_keys($externalPatientIdsWithNewData) as $externalPatientId) {
            $patient = PatientsCache::where('external_patient_id', $externalPatientId)->first();

            if ($patient && $this->riskClassificationService->classify($patient)) {
                $classifiedCount++;
            }
        }

        return $classifiedCount;
    }

    private function upsertPatient(array $row): void
    {
        $resolution = $this->wilayahResolver->resolve($row['kel_desa'] ?? null, $row['kecamatan'] ?? null);

        // Override manual (revisi Bu Kadis, kasus "kunjungan khusus di luar wilayah" -- mis.
        // Puskesmas Gapura punya pasien yang desa geografisnya resolve ke Kota Sumenep, tapi
        // pengirim aslinya Gapura) SENGAJA tidak pernah ditimpa sync otomatis berikutnya --
        // staf yang sudah menetapkan puskesmas_resolution_method='manual' via
        // PATCH /patients/{id}/override-puskesmas lebih tahu daripada resolusi otomatis.
        $existing = PatientsCache::where('external_patient_id', $row['patient_id'])->first();
        $isManualOverride = $existing?->puskesmas_resolution_method === 'manual';

        $puskesmas = $isManualOverride
            ? ['puskesmas_id' => $existing->puskesmas_id, 'method' => 'manual', 'pengirim_raw' => $existing->pengirim_raw]
            : $this->wilayahResolver->resolvePuskesmas($resolution->desaId, $resolution->kecamatanId, $row['patient_id']);

        PatientsCache::updateOrCreate(
            ['external_patient_id' => $row['patient_id']],
            [
                'no_reg' => $row['no_reg'] ?? null,
                'no_bpjs' => $row['no_bpjs'] ?? null,
                'nik_hash' => $row['nik_hash'],
                'nik' => $row['nik'] ?? null,
                'nama' => $row['name'],
                'gender' => $row['gender'] ?? null,
                'tgl_lahir' => $row['tgl_lahir'] ?? null,
                'phone' => $row['phone'] ?? null,
                'alamat' => $row['alamat'] ?? null,
                'rt_rw' => $row['rt_rw'] ?? null,
                'kel_desa_raw' => $row['kel_desa'] ?? null,
                'kecamatan_raw' => $row['kecamatan'] ?? null,
                'is_prolanis' => $row['is_prolanis'] ?? false,
                'jenis_prolanis' => $row['jenis_prolanis'] ?? null,
                'is_perokok' => $row['is_perokok'] ?? false,
                'jenis_perokok' => $row['jenis_perokok'] ?? null,
                'desa_id' => $resolution->desaId,
                'kecamatan_id' => $resolution->kecamatanId,
                'wilayah_status' => $resolution->wilayahStatus,
                'puskesmas_id' => $puskesmas['puskesmas_id'],
                'puskesmas_resolution_method' => $puskesmas['method'],
                'pengirim_raw' => $puskesmas['pengirim_raw'],
                'last_synced_at' => $row['updated_at'],
            ],
        );
    }

    private function upsertLabResultParameter(array $labResult, array $parameter): void
    {
        LabResultCache::updateOrCreate(
            ['external_id' => $labResult['lab_result_id'], 'parameter' => $parameter['parameter']],
            [
                'patient_id' => $labResult['patient_id'],
                'value' => (string) $parameter['hasil'],
                'satuan' => $parameter['satuan'] ?? null,
                'nilai_rujukan' => $parameter['nilai_rujukan'] ?? null,
                'class_hasil' => $parameter['class_hasil'] ?? null,
                'validation_status' => $parameter['validation_status'] ?? null,
                // Revisi Bu Kadis (Fase 5) -- teks bebas nama puskesmas/institusi pengirim
                // (docs/planning/04 §Endpoint 2), dipakai WilayahResolver sebagai sinyal
                // TAMBAHAN resolusi puskesmas, lihat resolvePuskesmas().
                'pengirim' => $labResult['pengirim'] ?? null,
                'tanggal_periksa' => $labResult['tanggal'],
                'synced_at' => $labResult['updated_at'],
            ],
        );
    }

    private function toIso8601(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
