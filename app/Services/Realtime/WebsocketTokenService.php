<?php

namespace App\Services\Realtime;

use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Terbitkan token socket produli-wss (dipanggil GET /api/v1/ws-token, auth:sanctum) --
 * format & algoritma HARUS SAMA PERSIS dengan ProduliWss.Auth.Token.verify/2 di sisi Phoenix:
 * "{base64url payload}.{base64url signature}", payload JSON {uid, role, pid, exp},
 * signature = HMAC-SHA256 atas payload MENTAH (sebelum base64) pakai WSS_TOKEN_SECRET.
 *
 * role/pid diambil dari role Spatie PERTAMA yang relevan untuk realtime (super_admin >
 * admin_puskesmas/pj_prolanis > lainnya) -- user teoretis bisa punya >1 role, tapi topic
 * yang di-generate frontend cuma butuh SATU role dominan untuk tahu topic mana yang boleh
 * di-join (lihat useRealtime.ts sisi frontend).
 */
class WebsocketTokenService
{
    public function issueFor(User $user): string
    {
        $secret = (string) config('produli.realtime.token_secret');

        if ($secret === '') {
            throw new RuntimeException('Konfigurasi WSS_TOKEN_SECRET belum diisi di .env.');
        }

        $ttl = (int) config('produli.realtime.token_ttl_seconds', 3600);

        $payload = [
            'uid' => $user->id,
            'role' => $this->dominantRole($user),
            'pid' => $user->puskesmas_id,
            'exp' => Carbon::now()->addSeconds($ttl)->timestamp,
        ];

        $payloadRaw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payloadRaw, $secret, true);

        return $this->base64UrlEncode($payloadRaw).'.'.$this->base64UrlEncode($signature);
    }

    private function dominantRole(User $user): string
    {
        foreach (['super_admin', 'admin_puskesmas', 'pj_prolanis', 'tenaga_kesehatan', 'kader'] as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return 'unknown';
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
