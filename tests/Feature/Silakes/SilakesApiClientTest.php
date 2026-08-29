<?php

namespace Tests\Feature\Silakes;

use App\Exceptions\SilakesApiException;
use App\Services\Silakes\SilakesApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresi untuk SilakesApiClient::post()/postPembaruanLapangan() — SATU-SATUNYA jalur tulis
 * ke SiLAKES (docs/planning/01 §9). Fokus: HMAC dihitung atas RAW BODY PERSIS yang dikirim
 * (bukan hasil json_encode ulang oleh Guzzle), dan pakai ability token TERPISAH (write_token),
 * bukan token baca (read token) yang dipakai GET.
 */
class SilakesApiClientTest extends TestCase
{
    private const BASE_URL = 'https://silakes.test';

    private const READ_TOKEN = 'read-token-abc';

    private const WRITE_TOKEN = 'write-token-xyz';

    private const SECRET = 'rahasia-uji';

    private function makeClient(?string $writeToken = self::WRITE_TOKEN): SilakesApiClient
    {
        return new SilakesApiClient(self::BASE_URL, self::READ_TOKEN, self::SECRET, $writeToken);
    }

    public function test_post_menghitung_hmac_atas_raw_body_persis_dan_pakai_write_token(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => null], 200),
        ]);

        $payload = ['latitude' => -7.0123456, 'longitude' => 113.8456789, 'produli_visit_id' => 42];

        $this->makeClient()->postPembaruanLapangan(999, $payload);

        Http::assertSent(function ($request) use ($payload) {
            $expectedRawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = $request->header('X-Timestamp')[0] ?? null;
            $expectedSignature = hash_hmac('sha256', $expectedRawBody.'.'.$timestamp, self::SECRET);

            return $request->body() === $expectedRawBody
                && $request->header('X-Signature')[0] === $expectedSignature
                && $request->header('Authorization')[0] === 'Bearer '.self::WRITE_TOKEN
                && str_contains($request->url(), '/api/v1/integration/patients/999/pembaruan-lapangan');
        });
    }

    public function test_post_lempar_exception_kalau_write_token_kosong(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token tulis SiLAKES belum diisi');

        $this->makeClient(writeToken: '')->postPembaruanLapangan(1, []);
    }

    public function test_post_boleh_pakai_token_override_eksplisit(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => null], 200)]);

        // write_token kosong TAPI token override diberikan langsung -> tidak boleh error.
        $this->makeClient(writeToken: '')->post('/api/v1/integration/patients/1/pembaruan-lapangan', [], 'token-override');

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer token-override');
    }

    public function test_response_gagal_melempar_silakes_api_exception_dengan_status_code_4xx_client_error(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Data tidak valid'], 422)]);

        try {
            $this->makeClient()->postPembaruanLapangan(1, []);
            $this->fail('Seharusnya melempar SilakesApiException.');
        } catch (SilakesApiException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertTrue($e->isClientError());
        }
    }

    public function test_response_5xx_tidak_dianggap_client_error(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Server error'], 500)]);

        try {
            $this->makeClient()->postPembaruanLapangan(1, []);
            $this->fail('Seharusnya melempar SilakesApiException.');
        } catch (SilakesApiException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertFalse($e->isClientError());
        }
    }

    public function test_response_200_tapi_status_envelope_bukan_success_tetap_melempar_exception(): void
    {
        // HTTP 200 tapi envelope {status,message,data} bukan "success" (mis. format tak terduga
        // dari SiLAKES) -> tetap harus dianggap gagal, jangan diperlakukan sebagai sukses diam-diam.
        Http::fake(['*' => Http::response(['status' => 'unexpected', 'message' => 'format tak dikenal', 'data' => null], 200)]);

        $this->expectException(SilakesApiException::class);

        $this->makeClient()->postPembaruanLapangan(1, []);
    }

    // ---- Retry otomatis khusus 429 (docs/planning/04 §3: rate limit 60 req/menit) ----

    public function test_429_diikuti_sukses_otomatis_retry_dan_berhasil(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::sequence()
                ->push(['status' => 'error', 'message' => 'Too Many Attempts'], 429)
                ->push(['status' => 'success', 'message' => 'ok', 'data' => null], 200),
        ]);

        $this->makeClient()->postPembaruanLapangan(1, []);

        Http::assertSentCount(2);
    }

    public function test_429_menghormati_header_retry_after(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::sequence()
                ->push(['status' => 'error', 'message' => 'Too Many Attempts'], 429, ['Retry-After' => '7'])
                ->push(['status' => 'success', 'message' => 'ok', 'data' => null], 200),
        ]);

        $this->makeClient()->postPembaruanLapangan(1, []);

        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 7.0);
    }

    public function test_429_yang_terus_menerus_akhirnya_tetap_melempar_silakes_api_exception(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::response(['status' => 'error', 'message' => 'Too Many Attempts'], 429),
        ]);

        try {
            $this->makeClient()->postPembaruanLapangan(1, []);
            $this->fail('Seharusnya melempar SilakesApiException setelah retry habis.');
        } catch (SilakesApiException $e) {
            $this->assertSame(429, $e->statusCode);
        }

        // RETRY_TIMES = 3 -> 3 percobaan TOTAL (bukan 3 retry di luar percobaan pertama).
        Http::assertSentCount(3);
    }

    public function test_5xx_tidak_ikut_di_retry(): void
    {
        Sleep::fake();

        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Server error'], 500)]);

        try {
            $this->makeClient()->postPembaruanLapangan(1, []);
            $this->fail('Seharusnya melempar SilakesApiException.');
        } catch (SilakesApiException $e) {
            $this->assertSame(500, $e->statusCode);
        }

        Http::assertSentCount(1);
    }

    // ---- Fase D (modul "Kirim Data Prolanis ke Labkesda Sumenep") ----
    // postProlanisDelivery()/getProlanisDeliveryStatus() cuma pemetik path tipis di atas
    // post()/get() yang sudah diuji lengkap (HMAC/retry/dst) di atas -- cukup pastikan path &
    // pemakaian write_token/read token-nya benar, tidak perlu mengulang semua skenario HMAC.

    public function test_post_prolanis_delivery_ke_path_yang_benar_pakai_write_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => ['silakes_delivery_id' => 1, 'items' => []]], 200)]);

        $this->makeClient()->postProlanisDelivery(['produli_pengiriman_sampel_id' => 1]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/integration/prolanis-deliveries')
            && $request->header('Authorization')[0] === 'Bearer '.self::WRITE_TOKEN);
    }

    public function test_get_prolanis_delivery_status_ke_path_yang_benar_pakai_read_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => ['status' => 'diterima']], 200)]);

        $this->makeClient()->getProlanisDeliveryStatus(42);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/integration/prolanis-deliveries/42')
            && $request->header('Authorization')[0] === 'Bearer '.self::READ_TOKEN);
    }

    // ---- PDF-proxy (permintaan user 2026-08-29): "puskesmas juga bisa mendownload dan melihat
    // hasil pemeriksaan ... layering di balik itu mendapatkan data PDF pemeriksaan dari silakes" ----

    public function test_get_patient_lab_documents_ke_path_yang_benar_pakai_read_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'message' => 'ok', 'data' => [
            ['surat_hasil_lab_id' => 5, 'tanggal' => '2026-08-01', 'jenis_spesimen' => 'Darah dan Urine', 'is_kunjungan_prolanis' => true, 'tgl_konfirmasi' => '2026-08-02'],
        ]], 200)]);

        $body = $this->makeClient()->getPatientLabDocuments(777);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/integration/patients/777/lab-documents')
            && $request->header('Authorization')[0] === 'Bearer '.self::READ_TOKEN);
        $this->assertSame(5, $body['data'][0]['surat_hasil_lab_id']);
    }

    public function test_get_lab_result_pdf_mengembalikan_byte_mentah_bukan_decode_json(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.4 isi-biner-palsu', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="hasil-5.pdf"',
        ])]);

        $pdf = $this->makeClient()->getLabResultPdf(5);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/integration/surat-hasil-labs/5/pdf')
            && $request->header('Authorization')[0] === 'Bearer '.self::READ_TOKEN);
        $this->assertSame('%PDF-1.4 isi-biner-palsu', $pdf['content']);
        $this->assertSame('application/pdf', $pdf['content_type']);
        $this->assertSame('hasil-5.pdf', $pdf['filename']);
    }

    public function test_get_lab_result_pdf_lempar_exception_kalau_gagal(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'not found'], 404)]);

        $this->expectException(SilakesApiException::class);

        $this->makeClient()->getLabResultPdf(999);
    }
}
