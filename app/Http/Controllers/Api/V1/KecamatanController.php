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
        // latitude/longitude (centroid kecamatan) -- dipakai useMapTileDownload.ts (frontend)
        // untuk menghitung bounding box unduhan peta offline saat kader memilih kecamatan secara
        // manual (kasus wilayah pasien ambigu, docs/planning/10 §5).
        $kecamatan = Kecamatan::orderBy('nama')
            ->get(['id', 'nama', 'kode_kemendagri', 'latitude', 'longitude'])
            // Cast eksplisit ke float -- lihat catatan sama di DesaController::index() (kolom
            // decimal:7 SELALU ter-serialize sebagai string, merusak aritmatika bbox di frontend
            // kalau dibiarkan).
            ->map(fn (Kecamatan $k) => [
                'id' => $k->id,
                'nama' => $k->nama,
                'kode_kemendagri' => $k->kode_kemendagri,
                'latitude' => $k->latitude !== null ? (float) $k->latitude : null,
                'longitude' => $k->longitude !== null ? (float) $k->longitude : null,
            ]);

        return ApiResponse::success($kecamatan);
    }
}
