<?php

namespace App\Services\Puskesmas;

use App\Models\Puskesmas;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

/**
 * Data Instansi (Puskesmas) -- docs/planning/02 §15. Tidak ada create()/delete() di sini
 * dengan sengaja -- penambahan/penutupan puskesmas tetap lewat migration/seeder terkontrol
 * (PuskesmasSeeder), bukan endpoint (§15: "peristiwa organisasi langka, bukan tombol UI").
 */
class PuskesmasService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Puskesmas::query()->with('kecamatan')->orderBy('nama')->paginate($perPage);
    }

    /**
     * @param  array{alamat?: ?string, no_telp?: ?string, no_wa?: ?string, latitude?: ?float, longitude?: ?float, deskripsi?: ?string}  $data
     */
    public function update(Puskesmas $puskesmas, array $data): Puskesmas
    {
        // `nama`/`kode_internal` (identitas resmi -- dokumen §15 menyebutnya "kode_kemendagri",
        // tapi kolom sesungguhnya di tabel puskesmas adalah `kode_internal`) SENGAJA tidak masuk
        // allow-list ini -- dikunci meski suatu saat FormRequest-nya berubah longgar (defense in
        // depth, pola sama seperti KaderService::updateOwnProfile()).
        $puskesmas->update(Arr::only($data, ['alamat', 'no_telp', 'no_wa', 'latitude', 'longitude', 'deskripsi']));

        return $puskesmas->fresh();
    }
}
