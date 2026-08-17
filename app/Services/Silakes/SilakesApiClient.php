<?php

namespace App\Services\Silakes;

use App\Exceptions\SilakesApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Client untuk endpoint integrasi SiLAKES. Kontrak: docs/planning/04-kontrak-api-silakes-aktual.md.
 * GET (endpoint 1-3) = read-only mutlak. POST (endpoint 4, pembaruan-lapangan) = SATU-SATUNYA
 * pengecualian, pakai ability token TERPISAH (docs/planning/01 §9) — lihat postPembaruanLapangan().
 *
 * Rate limit SiLAKES: 60 req/menit -> 429 (docs/planning/04 §3). get()/post() otomatis retry
 * KHUSUS untuk 429 (bukan 4xx lain -- itu tidak akan membaik dengan retry), menghormati header
 * Retry-After kalau SiLAKES mengirimkannya, else backoff tetap menaik. `throw: false` supaya
 * setelah retry habis, response yang gagal tetap balik apa adanya ke decode() di bawah --
 * jalur error yang sudah ada (SilakesApiException) tidak berubah sama sekali.
 */
class SilakesApiClient
{
    private const RETRY_TIMES = 3;

    private const RETRY_BASE_DELAY_MS = 1000;
    protected string $baseUrl;

    protected string $token;

    protected string $signatureSecret;

    protected string $writeToken;

    public function __construct(
        ?string $baseUrl = null,
        ?string $token = null,
        ?string $signatureSecret = null,
        ?string $writeToken = null,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('produli.silakes.base_url'), '/');
        $this->token = $token ?? (string) config('produli.silakes.token');
        $this->signatureSecret = $signatureSecret ?? (string) config('produli.silakes.signature_secret');
        // TIDAK divalidasi wajib di sini (beda dari 3 di atas) — cuma dibutuhkan saat benar-benar
        // memanggil endpoint tulis, supaya semua pemanggil GET (SyncSilakesService dkk) tidak
        // ikut gagal boot gara-gara token tulis belum diisi.
        $this->writeToken = $writeToken ?? (string) config('produli.silakes.write_token');

        if ($this->baseUrl === '' || $this->token === '' || $this->signatureSecret === '') {
            throw new RuntimeException(
                'Konfigurasi integrasi SiLAKES belum lengkap. Isi SILAKES_BASE_URL, SILAKES_API_TOKEN, dan PRODULI_INTEGRATION_SECRET di .env.'
            );
        }
    }

    /**
     * GET ke endpoint integrasi SiLAKES dengan header X-Signature/X-Timestamp (HMAC-SHA256
     * atas raw_body kosong, sesuai kontrak GET).
     *
     * @return array{status: string, message: string, data: mixed, meta?: array}
     */
    public function get(string $path, array $query = []): array
    {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', '.'.$timestamp, $this->signatureSecret);

        $response = $this->withRetryOn429(
            Http::baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->withHeaders([
                    'X-Signature' => $signature,
                    'X-Timestamp' => $timestamp,
                ])
                ->acceptJson()
                ->timeout(15)
        )->get($path, $query);

        return $this->decode($path, $response);
    }

    /**
     * POST ke endpoint integrasi SiLAKES. HMAC dihitung atas RAW BODY JSON PERSIS seperti yang
     * dikirim (bukan hasil json_encode ulang oleh Guzzle/Laravel) — makanya pakai withBody(),
     * bukan parameter $data biasa di Http::post(), supaya tidak ada mismatch byte antara yang
     * ditandatangani vs yang benar-benar dikirim.
     *
     * @return array{status: string, message: string, data: mixed, meta?: array}
     */
    public function post(string $path, array $payload, ?string $token = null): array
    {
        $bearerToken = $token ?? $this->writeToken;

        if ($bearerToken === '') {
            throw new RuntimeException(
                'Token tulis SiLAKES belum diisi. Isi SILAKES_WRITE_API_TOKEN di .env (docs/planning/01 §9).'
            );
        }

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $rawBody.'.'.$timestamp, $this->signatureSecret);

        $response = $this->withRetryOn429(
            Http::baseUrl($this->baseUrl)
                ->withToken($bearerToken)
                ->withBody($rawBody, 'application/json')
                ->withHeaders([
                    'X-Signature' => $signature,
                    'X-Timestamp' => $timestamp,
                ])
                ->acceptJson()
                ->timeout(15)
        )->post($path);

        return $this->decode($path, $response);
    }

    private function withRetryOn429(PendingRequest $request): PendingRequest
    {
        return $request->retry(
            self::RETRY_TIMES,
            function (int $attempt, Throwable $exception) {
                $retryAfter = $this->retryAfterSeconds($exception);

                return $retryAfter !== null ? $retryAfter * 1000 : $attempt * self::RETRY_BASE_DELAY_MS;
            },
            fn (Throwable $exception) => $this->isRateLimited($exception),
            throw: false,
        );
    }

    private function isRateLimited(Throwable $exception): bool
    {
        return $exception instanceof RequestException && $exception->response->status() === 429;
    }

    private function retryAfterSeconds(Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        $header = $exception->response->header('Retry-After');

        return $header !== '' ? (int) $header : null;
    }

    /**
     * @return array{status: string, message: string, data: mixed, meta?: array}
     */
    private function decode(string $path, Response $response): array
    {
        if ($response->failed()) {
            throw new SilakesApiException(
                sprintf(
                    'Panggilan SiLAKES %s gagal (HTTP %d): %s',
                    $path,
                    $response->status(),
                    $response->json('message') ?? $response->body(),
                ),
                $response->status(),
            );
        }

        $body = $response->json();

        if (($body['status'] ?? null) !== 'success') {
            throw new SilakesApiException(
                sprintf('Respons SiLAKES %s bukan status success: %s', $path, $body['message'] ?? 'unknown error'),
                $response->status(),
            );
        }

        return $body;
    }

    /**
     * GET /api/v1/integration/master-wilayah?level=...
     *
     * @return array<int, array{code: string, name: string, latitude: ?float, longitude: ?float}>
     */
    public function masterWilayah(string $level, array $params = []): array
    {
        $body = $this->get('/api/v1/integration/master-wilayah', array_merge(['level' => $level], $params));

        return $body['data'] ?? [];
    }

    /**
     * GET /api/v1/integration/patients?since=&cursor=&per_page= — paginasi cursor, dipanggil berulang
     * oleh SyncSilakesService sampai meta.has_more=false.
     *
     * is_prolanis=1 dipaksa selalu terkirim (menimpa $params kalau ada) — PRODULI secara mandat
     * cuma untuk program Prolanis, tidak ada alasan menarik/menyimpan data pasien non-Prolanis.
     *
     * @return array{status: string, message: string, data: array, meta: array{per_page:int,has_more:bool,next_cursor:?string}}
     */
    public function patients(array $params = []): array
    {
        return $this->get('/api/v1/integration/patients', array_merge($params, ['is_prolanis' => 1]));
    }

    /**
     * GET /api/v1/integration/lab-results?since=&cursor=&per_page= — hanya hasil FINAL
     * (status=completed DAN status_konfirmasi=approved), sudah difilter di sisi SiLAKES.
     *
     * is_kunjungan_prolanis=1 dipaksa selalu terkirim (menimpa $params kalau ada), pola yang
     * sama seperti is_prolanis di patients() — PRODULI secara mandat cuma untuk program
     * Prolanis, tidak ada alasan menarik/menyimpan hasil lab dari kunjungan non-Prolanis.
     *
     * @return array{status: string, message: string, data: array, meta: array{per_page:int,has_more:bool,next_cursor:?string}}
     */
    public function labResults(array $params = []): array
    {
        return $this->get('/api/v1/integration/lab-results', array_merge($params, ['is_kunjungan_prolanis' => 1]));
    }

    /**
     * GET /api/v1/integration/reference-ranges — nilai rujukan terstruktur umur+gender (12
     * parameter cakupan SiLAKES). TIDAK dipaginasi (dataset kecil, ~143 baris, satu kali fetch
     * penuh setiap sync) — beda dari patients()/labResults() yang delta-sync dengan cursor.
     *
     * @return array{status: string, message: string, data: array{aliases: array<string,string>, tensi_alias: string, ranges: array<string, array<int, array<string, mixed>>>}}
     */
    public function referenceRanges(): array
    {
        return $this->get('/api/v1/integration/reference-ranges');
    }

    /**
     * POST /api/v1/integration/patients/{patient_id}/pembaruan-lapangan — SATU-SATUNYA
     * pengecualian dari aturan read-only (docs/planning/01 §9). Semua field body opsional
     * kecuali patient_id di URL. Hasilnya SELALU pending_review di SiLAKES — PRODULI tidak
     * polling status, sinkronisasi rutin (updated_at delta sync) menangkap perubahan begitu
     * staf Labkesda approve.
     *
     * @return array{status: string, message: string, data: mixed}
     */
    public function postPembaruanLapangan(int $externalPatientId, array $payload): array
    {
        return $this->post("/api/v1/integration/patients/{$externalPatientId}/pembaruan-lapangan", $payload);
    }

    /**
     * GET /api/v1/integration/patients/{patient_id}/pembaruan-lapangan — riwayat usulan yang
     * PERNAH DIKIRIM PRODULI (sumber kopipu_kunjungan/kopipu_dashboard) untuk 1 pasien, termasuk
     * status pending_review/approved/rejected. Endpoint BACA (pakai token baca yang sama seperti
     * patients()/labResults(), BUKAN write token) -- revisi dari keputusan awal "PRODULI tidak
     * perlu polling status" (docs/planning/01 §9), sekarang dibutuhkan supaya staf bisa lihat
     * status usulannya tanpa menunggu sync rutin berikutnya.
     *
     * @return array{status: string, message: string, data: array}
     */
    public function getPembaruanLapanganHistory(int $externalPatientId): array
    {
        return $this->get("/api/v1/integration/patients/{$externalPatientId}/pembaruan-lapangan");
    }
}
