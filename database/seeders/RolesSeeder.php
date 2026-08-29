<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * 6 role dasar sesuai docs/planning/02 §7 (revisi Bu Kadis menambah `tenaga_kesehatan` --
 * peran pemeriksaan lanjutan di rumah pasien, beda dari kader yang fokus pendampingan minum
 * obat; modul Kirim Data Prolanis ke Labkesda menambah `pengantar_sampel` -- kurir yang
 * ditugaskan admin_puskesmas/pj_prolanis mengantar sampel fisik+data pasien). Scoping data
 * (puskesmas_id) dicek di Policy class per resource, bukan lewat permission granular di sini —
 * role cuma menjawab "siapa user ini", Policy yang menjawab "data mana yang boleh dia lihat/ubah".
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader', 'tenaga_kesehatan', 'pengantar_sampel'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
