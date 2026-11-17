<?php

namespace App\Services\Silakes;

use App\Exceptions\SinkronSedangBerjalanException;
use App\Models\IntegrationSyncLog;
use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\PatientSyncHistory;
use App\Models\ReferenceRangeCache;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use App\Services\Risk\RiskClassificationService;
use App\Services\Wilayah\WilayahResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Kunci bersama (revisi 2026-11-17): sinkron terjadwal 02:00, tombol "Sinkronisasi SiLAKES"
     * dan produli:isi-ulang-pasien tidak boleh berjalan bersamaan. Sebelumnya cron dan tombol
     * manual bisa tumpang tindih. TTL 2 jam: bila proses mati di tengah, kunci lepas sendiri.
     */
    public const KUNCI = 'produli:silakes-sync';

    public const KUNCI_TTL_DETIK = 7200;

    /** Batas "data baru" pasien setelah syncPatients() selesai, disimpan di log sinkron. */
    private ?string $patientsWatermark = null;

    /** Jalur sinkron yang sedang berjalan, dicatat di patients_cache.refreshed_via: harian | manual. */
    private string $via = 'harian';

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
        // Lab results DULUAN, baru patients -- urutan ini dipertahankan dari masa gerbang
        // kelayakan (dicabut 2026-11-17); klasifikasi tetap dijalankan setelah keduanya.
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
    public function runAndLog(string $via = 'harian'): array
    {
        $this->via = $via;
        $kunci = Cache::lock(self::KUNCI, self::KUNCI_TTL_DETIK);
        if (! $kunci->get()) {
            throw new SinkronSedangBerjalanException;
        }

        try {
            return $this->runAndLogTerkunci();
        } finally {
            $kunci->release();
        }
    }

    private function runAndLogTerkunci(): array
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
                'details' => $result + ['patients_watermark' => $this->patientsWatermark],
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

            $this->kabariSinkronGagal($e);

            throw $e;
        }
    }

    /**
     * Peringatan sinkron gagal ke semua super_admin (blueprint §3c). Kegagalan memberi tahu bukan
     * alasan menutupi galat aslinya, jadi galat notifikasi hanya dicatat.
     */
    private function kabariSinkronGagal(Throwable $e): void
    {
        try {
            $pesan = mb_substr($e->getMessage(), 0, 300);
            foreach (\App\Models\User::role('super_admin')->get() as $admin) {
                $this->notifyService->notify(
                    NotifiableTarget::user($admin),
                    new NotificationPayload(
                        type: 'silakes_sync_failed',
                        title: 'Sinkronisasi SiLAKES gagal',
                        body: "Sinkron data pasien & hasil lab gagal: {$pesan}",
                        data: ['type' => 'silakes_sync_failed', 'title' => 'Sinkronisasi SiLAKES gagal', 'body' => "Sinkron data pasien & hasil lab gagal: {$pesan}"],
                    ),
                    ['push', 'fcm', 'email'],
                );
            }
        } catch (Throwable $notifGagal) {
            report($notifGagal);
        }
    }

    /**
     * Tarik SEMUA pasien Prolanis dari SiLAKES tanpa batas waktu (produli:tarik-semua-pasien),
     * untuk pasien yang belum pernah masuk karena gerbang lama "harus punya hasil lab". Memakai
     * kunci sinkron yang sama dan TIDAK mengubah batas "data baru" sinkron harian.
     *
     * @return array{dipindai: int, baru: int, diperbarui: int}
     */
    public function tarikSemuaPasien(bool $uji = false): array
    {
        $kunci = Cache::lock(self::KUNCI, self::KUNCI_TTL_DETIK);
        if (! $kunci->get()) {
            throw new SinkronSedangBerjalanException;
        }

        $hasil = ['dipindai' => 0, 'baru' => 0, 'diperbarui' => 0];
        try {
            $cursor = null;
            do {
                $body = $this->client->patients(array_filter(['cursor' => $cursor, 'per_page' => self::PER_PAGE]));
                $baris = $body['data'] ?? [];
                $ada = PatientsCache::whereIn('external_patient_id', array_column($baris, 'patient_id'))->pluck('external_patient_id')->flip();
                foreach ($baris as $row) {
                    $hasil['dipindai']++;
                    $ada->has($row['patient_id']) ? $hasil['diperbarui']++ : $hasil['baru']++;
                    if (! $uji) {
                        $this->upsertPatient($row, 'manual');
                    }
                }

                $cursor = $body['meta']['next_cursor'] ?? null;
                $lanjut = (bool) ($body['meta']['has_more'] ?? false) && $cursor;
                if ($lanjut) {
                    Sleep::for(self::PAGE_DELAY_MS)->milliseconds();
                }
            } while ($lanjut);
        } finally {
            $kunci->release();
        }

        return $hasil;
    }

    /**
     * Hanya menarik pasien Prolanis — filter is_prolanis=1 dipaksa di SilakesApiClient::patients(),
     * bukan di sini, supaya berlaku untuk pemanggil mana pun (mandat PRODULI, bukan pilihan sync ini saja).
     *
     * Sejak 2026-11-17 (blueprint §9 no. 1) SEMUA pasien Prolanis ditarik, termasuk yang belum
     * punya hasil lab (dulu dilewati). produli:prune-ineligible-patients karenanya dinonaktifkan.
     */
    public function syncPatients(): int
    {
        $since = $this->patientsSince();
        $watermark = $since ? Carbon::parse($since) : null;
        $cursor = null;
        $count = 0;

        do {
            $body = $this->client->patients(array_filter([
                'since' => $since,
                'cursor' => $cursor,
                'per_page' => self::PER_PAGE,
            ]));

            // Semua pasien Prolanis ditarik (keputusan blueprint §9 no. 1, 2026-11-17), termasuk
            // yang belum punya hasil lab; sebelumnya hanya yang sudah punya hasil lab.
            foreach ($body['data'] ?? [] as $row) {
                $this->upsertPatient($row, $this->via);
                $count++;

                $diubah = ! empty($row['updated_at']) ? Carbon::parse($row['updated_at']) : null;
                if ($diubah && (! $watermark || $diubah->gt($watermark))) {
                    $watermark = $diubah;
                }
            }

            $cursor = $body['meta']['next_cursor'] ?? null;
            $hasMore = (bool) ($body['meta']['has_more'] ?? false);

            if ($hasMore && $cursor) {
                Sleep::for(self::PAGE_DELAY_MS)->milliseconds();
            }
        } while ($hasMore && $cursor);

        $this->patientsWatermark = $watermark?->toIso8601String();

        return $count;
    }

    /**
     * Mulai dari mana pasien ditarik (revisi 2026-11-17). Dulu max(patients_cache.last_synced_at),
     * tapi sejak SiLAKES mendorong hasil persetujuan seketika (SilakesEventService), satu pasien
     * bisa tersimpan dengan updated_at "sekarang" di siang hari; batas itu lalu melompat dan
     * pasien lain yang diubah lebih awal di hari yang sama tidak pernah tertarik. Sekarang batasnya
     * disimpan oleh sinkron sukses terakhir sendiri (details.patients_watermark); log lama tanpa
     * nilai itu memakai cara lama sebagai awal.
     */
    public function patientsSince(): ?string
    {
        $terakhir = IntegrationSyncLog::where('service_name', 'SyncSilakesService')
            ->where('status', 'success')
            ->latest('id')
            ->first();

        return $terakhir?->details['patients_watermark'] ?? $this->toIso8601(PatientsCache::max('last_synced_at'));
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

    /** Kolom yang perubahannya dicatat di patient_sync_histories (bukan kolom teknis/waktu). */
    private const KOLOM_RIWAYAT = [
        'no_reg', 'no_bpjs', 'nik', 'nama', 'gender', 'tgl_lahir', 'tempat_lahir', 'golongan_darah', 'agama',
        'status_perkawinan', 'pekerjaan', 'is_bpjs', 'fktp', 'phone', 'email', 'alamat', 'rt_rw', 'kel_desa_raw',
        'kecamatan_raw', 'is_prolanis', 'jenis_prolanis', 'is_perokok', 'jenis_perokok', 'domisili_alamat',
        'domisili_rt_rw', 'domisili_kel_desa', 'domisili_kecamatan', 'puskesmas_id',
    ];

    /**
     * Pemetaan SATU pasien SiLAKES ke patients_cache (blueprint §4b "PatientSyncMapper"), dipakai
     * semua jalur: sinkron harian, tombol manual, tarik penuh, dan event realtime
     * (SilakesEventService). $row berbentuk item GET /integration/patients.
     *
     * - Kolom yang tidak dikirim SiLAKES (payload lama) TIDAK dikosongkan.
     * - Puskesmas terkunci (manual / puskesmas_locked_at) tidak dihitung ulang; pasien yang baru
     *   pertama kali mendapat puskesmas langsung dikunci (§4d).
     * - Perubahan dicatat di patient_sync_histories. Tarikan pertama dengan pemetaan ini
     *   (refreshed_at masih kosong) dianggap data awal, bukan perubahan.
     *
     * @param  string  $via  harian | manual | realtime
     */
    public function upsertPatient(array $row, string $via = 'harian', ?string $event = null): PatientsCache
    {
        $resolution = $this->wilayahResolver->resolve($row['kel_desa'] ?? null, $row['kecamatan'] ?? null);
        $existing = PatientsCache::where('external_patient_id', $row['patient_id'])->first();

        // Override manual (revisi Bu Kadis) dan kunci kepemilikan (§4d) tidak pernah ditimpa.
        $puskesmas = $existing?->puskesmasTerkunci()
            ? ['puskesmas_id' => $existing->puskesmas_id, 'method' => $existing->puskesmas_resolution_method, 'pengirim_raw' => $existing->pengirim_raw]
            : $this->wilayahResolver->resolvePuskesmas($resolution->desaId, $resolution->kecamatanId, $row['patient_id']);

        $attrs = [
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
            'silakes_updated_at' => $row['updated_at'],
            'refreshed_at' => now(),
            'refreshed_via' => $via,
        ];

        // Fase 1/2 (2026-11-17): hanya bila SiLAKES sudah mengirimnya.
        foreach (['tempat_lahir', 'golongan_darah', 'agama', 'status_perkawinan', 'pekerjaan', 'is_bpjs', 'fktp', 'email'] as $k) {
            if (array_key_exists($k, $row)) {
                $attrs[$k] = $row[$k];
            }
        }
        if (array_key_exists('domisili', $row)) {
            $d = is_array($row['domisili']) ? $row['domisili'] : [];
            $attrs['domisili_alamat'] = $d['alamat'] ?? null;
            $attrs['domisili_rt_rw'] = $d['rt_rw'] ?? null;
            $attrs['domisili_kel_desa'] = $d['kel_desa'] ?? null;
            $attrs['domisili_kecamatan'] = $d['kecamatan'] ?? null;
            $attrs['domisili_sumber'] = $d['sumber'] ?? null;
            $attrs['domisili_updated_at'] = $d['updated_at'] ?? null;
        }

        if ($attrs['puskesmas_id'] !== null && ! $existing?->puskesmas_locked_at) {
            $attrs['puskesmas_locked_at'] = now();
        }

        if (! $existing) {
            return PatientsCache::create(['external_patient_id' => $row['patient_id']] + $attrs);
        }

        $sudahPunyaDataAwal = $existing->refreshed_at !== null;
        $existing->fill($attrs);
        $berubah = array_intersect_key($existing->getDirty(), array_flip(self::KOLOM_RIWAYAT));
        $lama = array_intersect_key($existing->getOriginal(), $berubah);
        $existing->save();

        if ($sudahPunyaDataAwal && $berubah !== []) {
            $catatan = [];
            foreach ($berubah as $kolom => $nilaiBaru) {
                $catatan[$kolom] = ['lama' => $this->nilaiRiwayat($lama[$kolom] ?? null), 'baru' => $this->nilaiRiwayat($existing->getAttribute($kolom))];
            }
            PatientSyncHistory::create([
                'patients_cache_id' => $existing->id,
                'synced_at' => now(),
                'sumber' => $via,
                'silakes_event' => $event,
                'changed_fields' => $catatan,
            ]);
        }

        return $existing;
    }

    private function nilaiRiwayat(mixed $v): mixed
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
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
