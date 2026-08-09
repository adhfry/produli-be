<?php

namespace App\Http\Middleware;

use App\Exceptions\OnboardingRequiredException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang onboarding_completed_at (docs/planning/02 §14) -- dipasang HANYA di route yang
 * memang perlu digerbangi (routes/api.php), SETELAH password.changed (user harus tuntas ganti
 * password dulu baru diminta onboarding); /auth/me, /auth/logout, /auth/change-password,
 * /auth/onboarding/complete SENGAJA TIDAK dipasangi middleware ini sama sekali (bukan
 * dikecualikan lewat cek path di sini) supaya user tetap punya jalan keluar dari gerbang ini.
 */
class EnsureOnboardingCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->onboarding_completed_at === null) {
            throw new OnboardingRequiredException('Anda wajib menyelesaikan onboarding terlebih dahulu sebelum melanjutkan.');
        }

        return $next($request);
    }
}
