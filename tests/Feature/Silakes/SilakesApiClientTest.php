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
}
