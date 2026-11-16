<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StoreKtpScanRequest;
use App\Models\KtpScan;
use App\Services\Ktp\KtpScanService;
use App\Support\ApiResponse;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scan KTP pasien (revisi 2026-11-16) untuk form "Ajukan Update Data Pasien" dan form pasien
 * baru di pengiriman sampel. Lihat KtpScanService.
 */
class KtpScanController extends Controller
{
    public function __construct(private readonly KtpScanService $service) {}

    public function store(StoreKtpScanRequest $request): JsonResponse
    {
        $scan = $this->service->scan($request->file('image'), $request->user());

        return ApiResponse::success([
            'id' => $scan->id,
            'fields' => $scan->ocr_fields,
            'checks' => $scan->ocr_checks ?? [],
            'ocr_error' => $scan->ocr_error,
            'existing_patient_id' => $this->service->existingPatientId($scan),
        ], $scan->ocr_error ? 'Foto KTP tersimpan, tetapi belum terbaca otomatis.' : 'KTP terbaca. Periksa kembali sebelum mengirim.', 201);
    }

    /** Pratinjau foto untuk pengunggahnya sendiri, staf satu puskesmas, atau super_admin. */
    public function image(Request $request, KtpScan $ktpScan): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        $boleh = DataScope::isFullAccess($user)
            || $ktpScan->user_id === $user->id
            || ($ktpScan->puskesmas_id !== null && $ktpScan->puskesmas_id === $user->puskesmas_id);

        if (! $boleh) {
            return ApiResponse::error('Anda tidak berhak melihat foto ini.', 403);
        }

        if (! Storage::disk($ktpScan->disk)->exists($ktpScan->path)) {
            return ApiResponse::error('Foto KTP tidak ditemukan.', 404);
        }

        return Storage::disk($ktpScan->disk)->response($ktpScan->path, null, [
            'Content-Type' => $ktpScan->mime,
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
