<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\DTO\TokenPair;
use App\Exceptions\InvalidAuthTokenException;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActivateAccountRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\CompleteOnboardingRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResendActivationRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\UploadAvatarRequest;
use App\Models\User;
use App\Services\Auth\AccountActivationService;
use App\Services\Auth\AuthTokenService;
use App\Services\Auth\GoogleAccountLinkService;
use App\Services\Auth\ProfileService;
use App\Services\Kader\KaderService;
use App\Support\ApiResponse;
use App\Support\DataScope;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Cookie as CookieValueObject;

class AuthController extends Controller
{
    private const REFRESH_COOKIE_NAME = 'produli_refresh_token';

    public function __construct(
        private readonly AuthTokenService $tokens,
        private readonly AccountActivationService $accountActivations,
        private readonly GoogleAccountLinkService $googleLink,
        private readonly ProfileService $profile,
        private readonly KaderService $kaderService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! $user->password || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        $pair = $this->tokens->issue($user, $request->string('device_id'), $request->string('device_name') ?: null);

        return $this->tokenResponse($pair, $user, 'Login berhasil');
    }

    public function refresh(Request $request): JsonResponse
    {
        $raw = $request->cookie(self::REFRESH_COOKIE_NAME);
        $deviceId = $request->header('X-Device-Id');

        if (! $raw || ! $deviceId) {
            throw new InvalidAuthTokenException('Refresh token atau X-Device-Id tidak ditemukan.');
        }

        $pair = $this->tokens->refresh($raw, $deviceId);

        // Tidak perlu kirim ulang profil user di sini — client sudah punya dari login,
        // dan endpoint refresh tidak (belum tentu) authenticated via auth:sanctum.
        return $this->tokenResponse($pair, null, 'Token diperbarui');
    }

    public function logout(Request $request): JsonResponse
    {
        $raw = $request->cookie(self::REFRESH_COOKIE_NAME);

        if ($raw) {
            $this->tokens->revoke($raw);
        }

        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logout berhasil')->withCookie(
            Cookie::forget(self::REFRESH_COOKIE_NAME)
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'user' => $user,
            // Pola sama seperti StaffResource -- frontend butuh ini utuh tanpa nebak-nebak
            // dari role mana yang berlaku untuk scoping data (docs/planning/02 §7).
            'roles' => $user->getRoleNames(),
        ]);
    }

    /**
     * Tukar exchange code sekali-pakai (dari GoogleAuthController::callback) jadi access+refresh
     * token — lewat jalur penerbitan token yang sama persis dengan login email/password.
     */
    public function exchangeGoogle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:150'],
        ]);

        $userId = Cache::pull(GoogleAuthController::EXCHANGE_CACHE_PREFIX.$data['code']);

        if (! $userId) {
            throw new InvalidAuthTokenException('Kode login Google tidak valid atau sudah kedaluwarsa, silakan login ulang.');
        }

        $user = User::findOrFail($userId);
        $pair = $this->tokens->issue($user, $data['device_id'], $data['device_name'] ?? null);

        return $this->tokenResponse($pair, $user, 'Login Google berhasil');
    }

    /**
     * Endpoint PUBLIK (belum login) -- user yang baru didaftarkan (POST /api/v1/staff atau
     * /api/v1/kader) menukar token dari email aktivasi jadi password. Password plaintext HANYA
     * dikembalikan di response ini, sekali -- tidak pernah disimpan/di-log di server.
     */
    public function activate(ActivateAccountRequest $request): JsonResponse
    {
        $result = $this->accountActivations->activate($request->string('token')->toString());

        return ApiResponse::success([
            'email' => $result['user']->email,
            'password' => $result['plain_password'],
            'must_change_password' => true,
        ], 'Akun berhasil diaktifkan — password ini hanya ditampilkan sekali, segera login dan ganti password.');
    }

    /**
     * super_admin selalu boleh; admin_puskesmas/pj_prolanis cuma boleh untuk user di
     * puskesmas_id yang sama dengan miliknya sendiri (docs/planning/02 §7, sama pola dengan
     * ScopesByPuskesmas::sharesPuskesmas() tapi inline di sini -- User bukan resource ber-Policy).
     */
    public function resendActivation(ResendActivationRequest $request): JsonResponse
    {
        $requester = $request->user();
        $targetUser = User::findOrFail($request->validated('user_id'));

        $authorized = DataScope::isFullAccess($requester)
            || ($requester->hasAnyRole(['admin_puskesmas', 'pj_prolanis'])
                && $requester->puskesmas_id !== null
                && $requester->puskesmas_id === $targetUser->puskesmas_id);

        if (! $authorized) {
            throw new AuthorizationException('Anda tidak memiliki akses untuk mengirim ulang aktivasi akun ini.');
        }

        $this->accountActivations->resend($targetUser, $requester);

        return ApiResponse::success(null, 'Email aktivasi berhasil dikirim ulang.');
    }

    /**
     * Satu-satunya endpoint selain /auth/me dan /auth/logout yang TIDAK digerbangi
     * EnsurePasswordChanged (routes/api.php) -- ini justru jalan keluarnya must_change_password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->string('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password lama tidak cocok.'],
            ]);
        }

        $user->update([
            // Cast 'hashed' di User model otomatis meng-hash nilai plaintext ini saat disimpan.
            'password' => $request->string('new_password')->toString(),
            'must_change_password' => false,
        ]);

        return ApiResponse::success(null, 'Password berhasil diganti.');
    }

    /**
     * Pakai Password Broker bawaan Laravel (password_reset_tokens, config/auth.php) -- bukan
     * infrastruktur token baru. Selalu balas pesan generik yang sama terlepas email terdaftar
     * atau tidak (cegah user enumeration), KECUALI kena throttle bawaan broker (itu murni rate
     * limit, aman dibedakan).
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => ['Terlalu banyak permintaan reset password, coba lagi nanti.'],
            ]);
        }

        return ApiResponse::success(null, 'Kalau email terdaftar, link reset password sudah dikirim.');
    }

    /**
     * Reset password = sinyal keamanan (mis. akun kemungkinan dibobol) -- setelah berhasil,
     * must_change_password dimatikan (password ini SUDAH dipilih user sendiri, bukan password
     * random dari aktivasi) DAN semua refresh_tokens user ini di-revoke, sama seperti tindakan
     * reuse-detection di AuthTokenService::refresh() -- paksa re-login di semua device.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                ])->save();

                $this->tokens->revokeAllForUser($user->id);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Pesan sendiri (bukan __($status)) -- proyek ini tidak publish lang/ files, jadi
            // string bawaan Laravel ('passwords.token' dkk) akan tampil mentah tanpa terjemahan.
            throw ValidationException::withMessages([
                'email' => [match ($status) {
                    Password::INVALID_TOKEN => 'Token reset password tidak valid atau sudah kedaluwarsa.',
                    Password::INVALID_USER => 'Email tidak terdaftar.',
                    Password::RESET_THROTTLED => 'Terlalu banyak percobaan reset password, coba lagi nanti.',
                    default => 'Gagal mereset password.',
                }],
            ]);
        }

        return ApiResponse::success(null, 'Password berhasil direset — silakan login ulang.');
    }

    /**
     * Endpoint AUTHENTICATED (Bearer token) yang HARUS menghasilkan redirect browser ke Google
     * -- tapi Bearer token tidak bisa ikut kalau browser navigasi langsung ke URL ini. Makanya
     * balikin JSON berisi redirect_url, frontend yang melakukan window.location = url tersebut
     * (bukan RedirectResponse langsung dari sini).
     */
    public function googleLinkRedirect(Request $request): JsonResponse
    {
        $token = $this->googleLink->createLinkToken($request->user());

        $redirectUrl = Socialite::driver('google')
            ->stateless()
            ->redirectUrl(route('auth.google.link.callback'))
            ->with(['state' => $token])
            ->redirect()
            ->getTargetUrl();

        return ApiResponse::success(['redirect_url' => $redirectUrl]);
    }

    public function unlinkGoogle(Request $request): JsonResponse
    {
        $this->googleLink->unlink($request->user());

        return ApiResponse::success(null, 'Akun Google berhasil dilepas.');
    }

    /**
     * Halaman Profil Saya & Pengaturan, semua role (docs/planning/02 §17) -- SELALU beroperasi
     * di profil milik user yang login, tidak ada parameter ID di route.
     */
    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = $this->profile->updateAvatar($request->user(), $request->file('avatar'));

        return ApiResponse::success(['user' => $user], 'Foto profil berhasil diperbarui.');
    }

    /**
     * Bukan pengganti PATCH /api/v1/kader/profile (itu tetap khusus field kader: no_wa/alamat/
     * gender/tgl_lahir) -- ini untuk field yang berlaku semua role (docs/planning/02 §17):
     * email_notifications_enabled, name, no_hp.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profile->updatePreferences($request->user(), $request->validated());

        return ApiResponse::success(['user' => $user], 'Profil berhasil diperbarui.');
    }

    /**
     * Onboarding First-Login (docs/planning/02 §14) -- payload profil (no_wa/alamat/gender/
     * tgl_lahir) dikirim di request yang sama, reuse endpoint update profil yang sesuai alih-alih
     * endpoint terpisah: kader -> KaderService::updateOwnProfile() (tabel kader), staf non-kader
     * (admin_puskesmas/pj_prolanis) -> ProfileService::updateStaffProfile() (tabel users, field
     * yang sama tidak pernah ada tempat menyimpannya sebelum ini -- staf dulu SELALU skip
     * langsung ke selesai di step 2, sekarang lanjut ke step 3 "Lengkapi Profil" seperti kader).
     */
    public function completeOnboarding(CompleteOnboardingRequest $request): JsonResponse
    {
        $user = $request->user();

        $profileData = $request->validated();
        if ($profileData !== []) {
            if ($user->kader !== null) {
                $this->kaderService->updateOwnProfile($user->kader, $profileData);
            } else {
                $this->profile->updateStaffProfile($user, $profileData);
            }
        }

        $user = $this->profile->completeOnboarding($user);

        return ApiResponse::success(['user' => $user], 'Onboarding berhasil diselesaikan.');
    }

    private function tokenResponse(TokenPair $pair, ?User $user, string $message): JsonResponse
    {
        return ApiResponse::success([
            'access_token' => $pair->accessToken,
            'token_type' => 'Bearer',
            'expires_at' => $pair->accessTokenExpiresAt->toIso8601String(),
            'user' => $user,
            // null di refresh() (user tidak dikirim ulang di sana) -- lihat catatan di refresh().
            'roles' => $user?->getRoleNames(),
        ], $message)->withCookie($this->refreshCookie($pair->rawRefreshToken, $pair->refreshTokenExpiresAt));
    }

    private function refreshCookie(string $rawRefreshToken, CarbonInterface $expiresAt): CookieValueObject
    {
        return cookie(
            name: self::REFRESH_COOKIE_NAME,
            value: $rawRefreshToken,
            minutes: now()->diffInMinutes($expiresAt),
            path: '/',
            domain: null,
            secure: ! app()->environment('local', 'testing'),
            httpOnly: true,
            sameSite: config('produli.auth.refresh_cookie_samesite', 'lax'),
        );
    }
}
