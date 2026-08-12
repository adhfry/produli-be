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
        FcmToken::updateOrCreate(
            ['token' => $request->string('token')->toString()],
            [
                'user_id' => $request->user()->id,
                'device_label' => $request->string('device_label')->toString() ?: null,
                'last_used_at' => now(),
            ],
        );

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
