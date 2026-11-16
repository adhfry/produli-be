<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang endpoint yang dipanggil SiLAKES (revisi 2026-11-17). Skema tanda tangan SAMA dengan
 * arah PRODULI -> SiLAKES: X-Signature = hmac_sha256(body.'.'.X-Timestamp, PRODULI_INTEGRATION_SECRET),
 * timestamp maksimal 300 detik dari sekarang. Tidak ada token: rahasia bersama itulah kuncinya.
 */
class VerifySilakesSignature
{
    private const WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('produli.silakes.signature_secret');
        $signature = (string) $request->header('X-Signature', '');
        $timestamp = (string) $request->header('X-Timestamp', '');

        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return ApiResponse::error('Tanda tangan SiLAKES tidak ada atau tidak lengkap.', 401);
        }

        if (abs(now()->timestamp - (int) $timestamp) > self::WINDOW_SECONDS) {
            return ApiResponse::error('Tanda tangan SiLAKES kedaluwarsa.', 401);
        }

        $expected = hash_hmac('sha256', $request->getContent().'.'.$timestamp, $secret);
        if (! hash_equals($expected, $signature)) {
            return ApiResponse::error('Tanda tangan SiLAKES tidak valid.', 401);
        }

        return $next($request);
    }
}
