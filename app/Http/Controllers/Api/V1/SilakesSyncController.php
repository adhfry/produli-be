<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSyncLog;
use App\Services\Silakes\SyncSilakesService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Trigger manual sinkronisasi SiLAKES dari UI (tombol "Sinkronisasi SiLAKES" di sidebar) --
 * super_admin saja. BEDA dari produli:sync-silakes (scheduler, throttle 48 jam) -- trigger
 * manual ini SELALU jalan begitu diklik (operator secara eksplisit minta sync sekarang), tidak
 * digerbangi throttle. Berjalan SYNCHRONOUS di request HTTP (bukan queued job) -- delta sync
 * harian (kasus normal setelah backfill awal) biasanya selesai dalam hitungan detik; full
 * backfill besar tetap sebaiknya lewat CLI (produli:sync-silakes), bukan tombol ini.
 */
class SilakesSyncController extends Controller
{
    /**
     * Waktu sinkronisasi TERAKHIR YANG SUKSES + status percobaan terakhir (bisa gagal) --
     * dipakai sidebar menampilkan "Terakhir sinkronisasi: ..." yang sungguhan, bukan teks statis.
     */
    public function status(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $lastSuccess = IntegrationSyncLog::where('service_name', 'SyncSilakesService')
            ->where('status', 'success')
            ->orderByDesc('requested_at')
            ->first();

        $lastAttempt = IntegrationSyncLog::where('service_name', 'SyncSilakesService')
            ->orderByDesc('requested_at')
            ->first();

        return ApiResponse::success([
            'last_synced_at' => $lastSuccess?->requested_at?->toIso8601String(),
            'last_attempt_at' => $lastAttempt?->requested_at?->toIso8601String(),
            'last_attempt_status' => $lastAttempt?->status,
        ]);
    }

    public function sync(Request $request, SyncSilakesService $service): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        try {
            $result = $service->runAndLog();
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Sinkronisasi gagal: '.$e->getMessage(),
                502,
            );
        }

        return ApiResponse::success([
            'result' => $result,
            'last_synced_at' => now()->toIso8601String(),
        ], 'Sinkronisasi SiLAKES berhasil');
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        if (! $request->user()->hasRole('super_admin')) {
            throw new AuthorizationException('Hanya super_admin yang dapat mengelola sinkronisasi SiLAKES');
        }
    }
}
