<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Kecamatan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Daftar referensi kecamatan (data administratif tetap, bukan data pasien) -- dipakai untuk
 * pilihan filter (mis. /dashboard/pasien) yang butuh id kanonik, bukan teks bebas
 * kecamatan_raw yang variannya tidak konsisten (lihat WilayahResolver). Semua role login,
 * tanpa scope -- sama seperti PuskesmasController::index(), ini data referensi wilayah,
 * bukan data pasien yang perlu diisolasi per puskesmas.
 */
class KecamatanController extends Controller
{
    public function index(): JsonResponse
    {
        $kecamatan = Kecamatan::orderBy('nama')->get(['id', 'nama', 'kode_kemendagri']);

        return ApiResponse::success($kecamatan);
    }
}
