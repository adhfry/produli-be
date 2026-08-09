<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * 4 role dasar sesuai docs/planning/02 §7. Scoping data (puskesmas_id) dicek di Policy
 * class per resource, bukan lewat permission granular di sini — role cuma menjawab
 * "siapa user ini", Policy yang menjawab "data mana yang boleh dia lihat/ubah".
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
