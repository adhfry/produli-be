<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleAccountLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

/**
 * Login Google — backend yang handle OAuth (client secret tidak boleh di Nuxt), docs/planning/02 §6.
 *
 * Alur: redirect -> Google -> callback (di sini) -> Laravel TEMUKAN User (google_id, atau
 * email yang sudah terdaftar lewat registrasi kader/staff -> tautkan google_id-nya), simpan
 * exchange code sekali-pakai (60 detik) -> redirect ke Nuxt cuma bawa code (BUKAN token asli,
 * supaya tidak nongkrong di URL/riwayat browser/log server). Nuxt lalu POST code itu ke
 * /api/v1/auth/google/exchange (AuthController) untuk ditukar jadi access+refresh token
 * lewat jalur yang sama persis dengan login email/password.
 *
 * REVISI §6: SENGAJA TIDAK PERNAH auto-create User baru di sini -- akun cuma dibuat lewat
 * jalur registrasi resmi (kader/staff, ada gate role/puskesmas). Kalau email dari Google tidak
 * ditemukan di users, redirect balik dengan ?error=account_not_found, tidak ada baris users
 * yang tercipta.
 *
 * linkCallback() (di bawah) BEDA alur -- itu untuk user yang SUDAH login (email/password) mau
 * menautkan akun Google-nya, bukan login. Lihat GoogleAccountLinkService untuk detail kenapa
 * pakai token cache sendiri (bukan Socialite session-based state).
 */
class GoogleAuthController extends Controller
{
    public const EXCHANGE_CACHE_PREFIX = 'google_auth_exchange:';

    public function __construct(private readonly GoogleAccountLinkService $googleLink) {}

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::where('google_id', $googleUser->getId())->first();

        if (! $user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                $user->update(['google_id' => $googleUser->getId()]);
            }
        }

        if (! $user) {
            // TIDAK auto-create (REVISI §6) -- akun cuma lewat registrasi resmi kader/staff.
            return redirect()->away(
                rtrim(config('app.frontend_url'), '/').'/auth/google/callback?error=account_not_found'
            );
        }

        $code = Str::random(40);
        Cache::put(self::EXCHANGE_CACHE_PREFIX.$code, $user->id, now()->addSeconds(60));

        return redirect()->away(
            rtrim(config('app.frontend_url'), '/').'/auth/google/callback?code='.$code
        );
    }

    /**
     * Publik (Google redirect ke sini tanpa header auth apa pun) -- identitas user yang menautkan
     * datang dari parameter 'state' (token cache, lihat AuthController::googleLinkRedirect()),
     * BUKAN dari sesi login. redirectUrl() di sini HARUS sama persis dengan yang dipakai saat
     * redirect awal (googleLinkRedirect()), kalau tidak Google menolak tukar code (redirect_uri
     * mismatch, aturan baku OAuth2 authorization code flow).
     */
    public function linkCallback(Request $request): RedirectResponse
    {
        $user = $this->googleLink->resolveUserFromState($request->query('state'));

        if (! $user) {
            return $this->redirectToFrontend('error', 'expired_or_invalid_state');
        }

        $googleUser = Socialite::driver('google')
            ->stateless()
            ->redirectUrl(route('auth.google.link.callback'))
            ->user();

        try {
            $this->googleLink->link($user, $googleUser->getId(), $googleUser->getEmail());
        } catch (ValidationException) {
            return $this->redirectToFrontend('error', 'conflict');
        }

        return $this->redirectToFrontend('success');
    }

    private function redirectToFrontend(string $status, ?string $reason = null): RedirectResponse
    {
        $query = http_build_query(array_filter(['google_link' => $status, 'reason' => $reason]));

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/settings/akun?'.$query);
    }
}
