<?php

namespace Database\Seeders;

use App\Models\Kecamatan;
use App\Models\Puskesmas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 31 baris puskesmas diturunkan dari tabel `kecamatan` yang sudah di-seed (docs/planning/02
 * §15, §2a) -- 1 puskesmas per kecamatan (nama sama), KECUALI 4 kecamatan dengan 2 puskesmas
 * (daftar pengecualian manual di bawah -- SATU-SATUNYA bagian hardcode di seeder ini, memang
 * perlu karena nama puskesmas tidak selalu sama dengan nama kecamatan, lihat §2a). 27 nama
 * kecamatan LAINNYA sengaja TIDAK di-hardcode -- diambil langsung dari data kecamatan
 * tersimpan, supaya seeder ini tidak pernah drift dari master data wilayah yang sebenarnya.
 *
 * Idempotent (upsert lewat kode_internal turunan nama) -- pola sama seperti MasterWilayahSeeder.
 *
 * Kolom kontak baru (no_telp/no_wa/latitude/longitude/deskripsi) SENGAJA dibiarkan NULL --
 * diisi belakangan lewat PATCH /api/v1/puskesmas/{id} oleh admin_puskesmas/super_admin,
 * BUKAN bagian dari data seed (docs/planning/02 §15).
 */
class PuskesmasSeeder extends Seeder
{
    /**
     * Kecamatan dengan LEBIH dari 1 puskesmas -- nama kecamatan (persis seperti tersimpan di
     * tabel kecamatan) => daftar nama puskesmas.
     *
     * @var array<string, array<int, string>>
     */
    private const PENGECUALIAN_DUA_PUSKESMAS = [
        'Kota Sumenep' => ['Pandian', 'Pamolokan'],
        'Batang Batang' => ['Batang-Batang', 'Legung'],
        'Lenteng' => ['Lenteng', 'Moncek Tengah'],
        'Sapeken' => ['Sapeken', 'Pagerungan Besar'],
    ];

    public function run(): void
    {
        $kecamatanList = Kecamatan::all();

        if ($kecamatanList->isEmpty()) {
            throw new RuntimeException(
                'Tabel kecamatan kosong -- jalankan MasterWilayahSeeder dulu sebelum PuskesmasSeeder (docs/planning/02 §15).'
            );
        }

        $created = 0;
        $updated = 0;

        foreach ($kecamatanList as $kecamatan) {
            $namaPuskesmasList = self::PENGECUALIAN_DUA_PUSKESMAS[$kecamatan->nama] ?? [$kecamatan->nama];

            foreach ($namaPuskesmasList as $namaPuskesmas) {
                $puskesmas = Puskesmas::firstOrNew(['kode_internal' => $this->kodeInternal($namaPuskesmas)]);
                $isNew = ! $puskesmas->exists;

                $puskesmas->fill([
                    'kabupaten_id' => $kecamatan->kabupaten_id,
                    'nama' => 'Puskesmas '.$namaPuskesmas,
                    'status_aktif' => true,
                ]);
                $puskesmas->save();

                $isNew ? $created++ : $updated++;
            }
        }

        $this->command?->info(sprintf(
            'Selesai: %d puskesmas baru, %d sudah ada (diperbarui). Total baris puskesmas: %d.',
            $created,
            $updated,
            Puskesmas::count(),
        ));
    }

    private function kodeInternal(string $namaPuskesmas): string
    {
        return 'PKM-'.Str::upper(Str::slug($namaPuskesmas));
    }
}
