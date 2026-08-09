<?php

namespace Tests\Feature\Silakes;

use App\Models\Desa;
use App\Models\Kabupaten;
use App\Models\Kecamatan;
use App\Models\LabResultCache;
use App\Models\PatientsCache;
use App\Models\RiskThreshold;
use App\Services\Silakes\SyncSilakesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Regresi untuk SyncSilakesService — dulu diverifikasi lewat tinker ad-hoc + Http::fake(),
 * sekarang dipermanenkan. Termasuk regresi untuk mandat "is_prolanis=1"/"is_kunjungan_prolanis=1
 * selalu dipaksa" di SilakesApiClient::patients()/labResults().
 */
class SyncSilakesServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $talango = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K28', 'nama' => 'Talango']);
        Desa::create(['kecamatan_id' => $talango->id, 'kode_kemendagri' => 'D-TALANGO', 'nama' => 'Talango']);
    }

    private function fakePatientsAndLabResults(): void
    {
        Http::fake([
            '*/api/v1/integration/patients*' => Http::response([
                'status' => 'success',
                'message' => 'ok',
                'data' => [
                    [
                        'patient_id' => 888001, 'no_reg' => 'REG-1', 'name' => 'Pasien Satu',
                        'nik_hash' => 'HASH1', 'gender' => 'L', 'tgl_lahir' => '1980-01-01',
                        'phone' => '0800000001', 'alamat' => 'Jl Uji 1', 'rt_rw' => '001/002',
                        'kel_desa' => 'Talango', 'kecamatan' => 'Talango',
                        'is_prolanis' => true, 'jenis_prolanis' => 'DM', 'is_perokok' => false, 'jenis_perokok' => null,
                        'updated_at' => '2026-07-30T10:00:00+00:00',
                    ],
                    [
                        'patient_id' => 888002, 'no_reg' => 'REG-2', 'name' => 'Pasien Dua',
                        'nik_hash' => 'HASH2', 'gender' => 'P', 'tgl_lahir' => '1975-05-05',
                        'phone' => '0800000002', 'alamat' => 'Jl Uji 2', 'rt_rw' => null,
                        'kel_desa' => 'Entah', 'kecamatan' => 'SURABAYA',
                        'is_prolanis' => true, 'jenis_prolanis' => null, 'is_perokok' => true, 'jenis_perokok' => 'aktif',
                        'updated_at' => '2026-07-30T10:05:00+00:00',
                    ],
                ],
                'meta' => ['per_page' => 200, 'has_more' => false, 'next_cursor' => null],
            ], 200),
            '*/api/v1/integration/lab-results*' => Http::response([
                'status' => 'success',
                'message' => 'ok',
                'data' => [
                    [
                        'lab_result_id' => 700001, 'patient_id' => 888001, 'tanggal' => '2026-07-29',
                        'status' => 'completed', 'status_konfirmasi' => 'approved',
                        'updated_at' => '2026-07-29T09:00:00+00:00',
                        'parameters' => [
                            ['parameter' => 'Gula Darah Puasa', 'satuan' => 'mg/dL', 'nilai_rujukan' => '70-110', 'hasil' => '250', 'class_hasil' => 'Tinggi', 'validation_status' => 'validated'],
                        ],
                    ],
                ],
                'meta' => ['per_page' => 200, 'has_more' => false, 'next_cursor' => null],
            ], 200),
        ]);
    }

    public function test_sync_patients_dan_lab_results_serta_trigger_klasifikasi(): void
    {
        $this->fakePatientsAndLabResults();
        RiskThreshold::create(['parameter' => 'Gula Darah Puasa', 'level' => 'berat', 'operator' => '>', 'threshold_min' => 200, 'is_active' => true]);

        $result = app(SyncSilakesService::class)->run();

        $this->assertSame(2, $result['patients_synced']);
        $this->assertSame(1, $result['lab_results_synced']);
        $this->assertSame(1, $result['patients_classified']);

        $p1 = PatientsCache::where('external_patient_id', 888001)->first();
        $p2 = PatientsCache::where('external_patient_id', 888002)->first();

        $this->assertSame('resolved', $p1->wilayah_status);
        $this->assertSame('out_of_scope', $p2->wilayah_status);
        // Kriteria REVISI (docs/planning/02 §3): Berat butuh keenam parameter lengkap
        // tersedia+melebihi. Fixture ini cuma punya Gula Darah Puasa -> Ringan, bukan Berat.
        $this->assertSame('ringan', $p1->riskClassifications()->where('is_latest', true)->first()->level);
    }

    public function test_hmac_signature_dan_timestamp_terkirim_di_setiap_request(): void
    {
        $this->fakePatientsAndLabResults();

        app(SyncSilakesService::class)->run();

        Http::assertSent(fn ($request) => $request->hasHeader('X-Signature') && $request->hasHeader('X-Timestamp'));
    }

    public function test_is_prolanis_1_selalu_dipaksa_di_query_patients(): void
    {
        // Regresi: KOPIPU secara mandat cuma untuk program Prolanis (lihat riwayat percakapan) —
        // SilakesApiClient::patients() HARUS selalu memaksa is_prolanis=1, apa pun query yang diminta caller.
        $this->fakePatientsAndLabResults();

        app(SyncSilakesService::class)->run();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/integration/patients')) {
                return true; // baris ini tidak relevan untuk request selain endpoint patients
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['is_prolanis'] ?? null) == '1';
        });
    }

    public function test_is_kunjungan_prolanis_1_selalu_dipaksa_di_query_lab_results(): void
    {
        // Regresi: SilakesApiClient::labResults() HARUS selalu memaksa is_kunjungan_prolanis=1,
        // pola yang sama seperti is_prolanis di patients() — kunjungan/hasil lab non-Prolanis
        // (mis. skrining narkoba) tidak boleh ikut tertarik.
        $this->fakePatientsAndLabResults();

        app(SyncSilakesService::class)->run();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/integration/lab-results')) {
                return true; // baris ini tidak relevan untuk request selain endpoint lab-results
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['is_kunjungan_prolanis'] ?? null) == '1';
        });
    }

    public function test_jeda_antar_halaman_saat_paginasi_lebih_dari_satu_halaman(): void
    {
        // Rate limit SiLAKES 60 req/menit (docs/planning/04 §3) -- paginasi >1 halaman HARUS
        // dijeda, bukan ditembak beruntun (itu penyebab 429 yang pernah kejadian nyata).
        Sleep::fake();

        Http::fake([
            '*/api/v1/integration/patients*' => Http::sequence()
                ->push([
                    'status' => 'success', 'message' => 'ok',
                    'data' => [[
                        'patient_id' => 888001, 'nik_hash' => 'HASH1', 'name' => 'Pasien Satu',
                        'kel_desa' => 'Talango', 'kecamatan' => 'Talango',
                        'updated_at' => '2026-07-30T10:00:00+00:00',
                    ]],
                    'meta' => ['per_page' => 200, 'has_more' => true, 'next_cursor' => 'halaman-2'],
                ], 200)
                ->push([
                    'status' => 'success', 'message' => 'ok', 'data' => [],
                    'meta' => ['per_page' => 200, 'has_more' => false, 'next_cursor' => null],
                ], 200),
            '*/api/v1/integration/lab-results*' => Http::response([
                'status' => 'success', 'message' => 'ok', 'data' => [],
                'meta' => ['per_page' => 200, 'has_more' => false, 'next_cursor' => null],
            ], 200),
        ]);

        app(SyncSilakesService::class)->run();

        // Cuma antara 2 halaman patients -- TIDAK sebelum halaman pertama, TIDAK setelah
        // halaman terakhir (has_more=false), dan lab-results di sini cuma 1 halaman (tanpa jeda).
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 1200.0, times: 1);
    }

    public function test_run_dua_kali_idempotent_tidak_duplikat(): void
    {
        $this->fakePatientsAndLabResults();

        app(SyncSilakesService::class)->run();
        $patientsBefore = PatientsCache::count();
        $labResultsBefore = LabResultCache::count();

        app(SyncSilakesService::class)->run();

        $this->assertSame($patientsBefore, PatientsCache::count());
        $this->assertSame($labResultsBefore, LabResultCache::count());
    }
}
