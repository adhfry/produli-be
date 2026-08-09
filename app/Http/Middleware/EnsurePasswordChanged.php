<?php

namespace App\Http\Middleware;

use App\Exceptions\MustChangePasswordException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang must_change_password (docs/planning/02, siklus password) -- dipasang HANYA di route
 * yang memang perlu digerbangi (routes/api.php); /auth/me, /auth/logout, /auth/change-password
 * SENGAJA TIDAK dipasangi middleware ini sama sekali (bukan dikecualikan lewat cek path di sini)
 * supaya user tetap punya jalan keluar walau wajib ganti password dulu.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            throw new MustChangePasswordException('Anda wajib mengganti password terlebih dahulu sebelum melanjutkan.');
        }

        return $next($request);
    }
}
