<?php

namespace App\Services\Silakes;

use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Services\Risk\RiskClassificationService;
use App\Services\Wilayah\WilayahResolver;
use Carbon\Carbon;
use Illuminate\Support\Sleep;

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
    ) {}

    /**
     * @return array{patients_synced: int, lab_results_synced: int, patients_classified: int}
     */
    public function run(): array
    {
        $patientsSynced = $this->syncPatients();
        [$labResultsSynced, $patientsClassified] = $this->syncLabResults();

        return [
            'patients_synced' => $patientsSynced,
            'lab_results_synced' => $labResultsSynced,
            'patients_classified' => $patientsClassified,
        ];
    }

    /**
     * Hanya menarik pasien Prolanis — filter is_prolanis=1 dipaksa di SilakesApiClient::patients(),
     * bukan di sini, supaya berlaku untuk pemanggil mana pun (mandat KOPIPU, bukan pilihan sync ini saja).
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
                $this->upsertPatient($row);
                $count++;
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
     * @return array{0: int, 1: int} [lab_results_synced, patients_classified]
     */
    public function syncLabResults(): array
    {
        $since = $this->toIso8601(LabResultCache::max('synced_at'));
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

        $classifiedCount = 0;

        foreach (array_keys($externalPatientIdsWithNewData) as $externalPatientId) {
            $patient = PatientsCache::where('external_patient_id', $externalPatientId)->first();

            if ($patient && $this->riskClassificationService->classify($patient)) {
                $classifiedCount++;
            }
        }

        return [$count, $classifiedCount];
    }

    private function upsertPatient(array $row): void
    {
        $resolution = $this->wilayahResolver->resolve($row['kel_desa'] ?? null, $row['kecamatan'] ?? null);
        $puskesmas = $this->wilayahResolver->resolvePuskesmas($resolution->desaId, $resolution->kecamatanId);

        PatientsCache::updateOrCreate(
            ['external_patient_id' => $row['patient_id']],
            [
                'no_reg' => $row['no_reg'] ?? null,
                'nik_hash' => $row['nik_hash'],
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
                'wilayah_status' => $resolution->wilayahStatus,
                'puskesmas_id' => $puskesmas['puskesmas_id'],
                'puskesmas_resolution_method' => $puskesmas['method'],
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
