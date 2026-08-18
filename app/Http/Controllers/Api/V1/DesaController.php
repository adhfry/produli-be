<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Desa;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar referensi desa/kelurahan (data administratif tetap, bukan data pasien) -- sama pola
 * dengan KecamatanController. Dipakai dropdown typeahead "Kel/Desa" & "Kecamatan" di form usulan
 * pembaruan data pasien (dashboard/pasien/[id].vue & app/kunjungan/[id].vue) supaya kader/staf
 * memilih nama desa/kecamatan KANONIK (casing sama persis dengan tabel desa/kecamatan) alih-alih
 * ketik bebas -- WilayahResolver jadi tidak perlu fuzzy-match untuk data yang masuk lewat jalur
 * ini. Semua role login, tanpa scope -- data referensi wilayah, bukan data pasien yang perlu
 * diisolasi per puskesmas.
 */
class DesaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $desa = Desa::query()
            ->when(
                $request->filled('kecamatan_id'),
                fn ($q) => $q->where('kecamatan_id', $request->integer('kecamatan_id'))
            )
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where('nama', 'like', '%'.addcslashes($request->string('search')->trim()->toString(), '%_\\').'%')
            )
            ->orderBy('nama')
            ->get(['id', 'kecamatan_id', 'nama', 'kode_kemendagri']);

        return ApiResponse::success($desa);
    }
}
