<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\RegisterFcmTokenRequest;
use App\Models\FcmToken;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registrasi/hapus token FCM (Firebase Cloud Messaging, push notification web PWA) milik user
 * yang login -- dipanggil frontend setelah getToken() sukses (izin notifikasi browser diberikan).
 * Upsert berdasar kolom token (bukan user_id) supaya idempotent -- browser yang sama bisa
 * register ulang tokennya (mis. re-login) tanpa bikin baris duplikat.
 */
class FcmTokenController extends Controller
{
    public function store(RegisterFcmTokenRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $token = $request->string('token')->toString();
        $deviceLabel = $request->string('device_label')->toString() ?: null;

        FcmToken::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $userId,
                'device_label' => $deviceLabel,
                'last_used_at' => now(),
            ],
        );

        // Bug notif dobel: Firebase kadang merotasi token FCM utk browser/device YANG SAMA
        // (service worker update, cache dibersihkan, dst) -- tanpa ini, token lama yang masih
        // valid tetap tersimpan selamanya (frontend tidak pernah panggil destroy() saat itu
        // terjadi, cuma saat logout eksplisit), jadi 1 device bisa punya >1 baris fcm_tokens
        // valid sekaligus -> sendToTokens() kirim ke semuanya -> user lihat notif dobel.
        // Hapus token LAIN milik user+device_label yang sama (device_label = userAgent, cukup
        // stabil per device) -- device lain (mis. HP + tablet) device_label-nya beda, tidak
        // ikut terhapus.
        if ($deviceLabel !== null) {
            FcmToken::where('user_id', $userId)
                ->where('device_label', $deviceLabel)
                ->where('token', '!=', $token)
                ->delete();
        }

        return ApiResponse::success(null, 'Token FCM terdaftar');
    }

    /**
     * Hapus HANYA kalau token itu memang milik user yang login -- tidak ada jalur hapus token
     * user lain lewat sini.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        FcmToken::where('user_id', $request->user()->id)
            ->where('token', $request->string('token')->toString())
            ->delete();

        return ApiResponse::success(null, 'Token FCM dihapus');
    }
}
