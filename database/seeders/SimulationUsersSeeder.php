<?php

namespace Database\Seeders;

use App\Models\Kader;
use App\Models\Puskesmas;
use App\Models\TenagaKesehatan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * KHUSUS branch `dev`/lingkungan simulasi -- bikin 86 akun demo (password sama untuk
 * semua, dikenal publik) supaya presentasi/uji coba end-to-end ke Bu Kadis bisa login
 * cepat per peran/puskesmas tanpa bikin akun manual satu-satu. SENGAJA menolak jalan di
 * APP_ENV=production (pola sama persis TestAccountSeeder::run()) -- kalau file ini
 * somehow ke-deploy ke server produksi, seeder tetap tidak akan pernah membuat akun
 * password lemah di sana.
 *
 * Idempoten: `makeUser()` cari user by email dulu (mirror TestAccountSeeder::makeUser()),
 * jadi aman dijalankan berkali-kali (mis. tiap kali `produli:seed-simulation` dipanggil)
 * tanpa membuat duplikat atau error.
 *
 * Lookup puskesmas SENGAJA dinamis lewat kode_internal (bukan ID numerik hardcode) --
 * kalau database dev dibangun dari mysqldump produksi, ID auto-increment aslinya tidak
 * bisa ditebak dari sini.
 */
class SimulationUsersSeeder extends Seeder
{
    private const PASSWORD = '12345678';

    /**
     * Nama puskesmas SEPERTI di PuskesmasSeeder::PENGECUALIAN_DUA_PUSKESMAS/nama kecamatan
     * biasa -- dipakai turunkan kode_internal ('PKM-'.Str::upper(Str::slug($nama))) dan slug
     * email ('pkm.{slug}.{role}{n}@gmail.com').
     *
     * @var array<int, string>
     */
    private const TARGET_PUSKESMAS = ['Manding', 'Gapura', 'Pandian', 'Pamolokan'];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SimulationUsersSeeder tidak boleh dijalankan di environment production.');
        }

        $this->call(RolesSeeder::class);

        $this->seedSuperAdmins();

        foreach (self::TARGET_PUSKESMAS as $namaPuskesmas) {
            $this->seedPuskesmasAccounts($namaPuskesmas);
        }

        // Tambahan kader nyata (bukan akun @gmail.com bodong) di Puskesmas Pandian, Kota
        // Sumenep -- permintaan eksplisit untuk simulasi.
        $pandian = $this->findPuskesmas('Pandian');
        $pjPandian1 = User::where('email', $this->emailFor('Pandian', 'pjprolanis', 1))->first();

        $extraKaderUser = $this->makeUser('Ahda Firly Barori (Kader)', 'ahdafirlybarori@gmail.com', $pandian->id);
        if (! $extraKaderUser->hasRole('kader')) {
            $extraKaderUser->assignRole('kader');
        }
        Kader::firstOrCreate(
            ['user_id' => $extraKaderUser->id],
            [
                'pj_id' => $pjPandian1?->id,
                'puskesmas_id' => $pandian->id,
                'status_aktif' => true,
                'no_hp' => '081200000099',
            ],
        );

        $this->command?->info('Selesai seed akun simulasi (super_admin + 4 puskesmas x 4 peran x 5 + 1 kader tambahan).');
    }

    private function seedSuperAdmins(): void
    {
        for ($n = 1; $n <= 5; $n++) {
            $user = $this->makeUser("Super Admin Simulasi {$n}", "superadmin{$n}@gmail.com", null);
            if (! $user->hasRole('super_admin')) {
                $user->assignRole('super_admin');
            }
        }

        // Akun asli developer -- tetap TIDAK menimpa nama kalau sudah pernah didaftarkan
        // manual sebelumnya (mirror TestAccountSeeder::makeUser()).
        $ahda = $this->makeUser('Ahda Firly Barori', 'ahda.creator@gmail.com', null);
        if (! $ahda->hasRole('super_admin')) {
            $ahda->assignRole('super_admin');
        }
    }

    private function seedPuskesmasAccounts(string $namaPuskesmas): void
    {
        $puskesmas = $this->findPuskesmas($namaPuskesmas);

        $pjUsers = [];
        for ($n = 1; $n <= 5; $n++) {
            $pj = $this->makeUser("PJ Prolanis {$namaPuskesmas} {$n}", $this->emailFor($namaPuskesmas, 'pjprolanis', $n), $puskesmas->id);
            if (! $pj->hasRole('pj_prolanis')) {
                $pj->assignRole('pj_prolanis');
            }
            $pjUsers[] = $pj;
        }
        $pjId = $pjUsers[0]->id;

        for ($n = 1; $n <= 5; $n++) {
            $admin = $this->makeUser("Admin Puskesmas {$namaPuskesmas} {$n}", $this->emailFor($namaPuskesmas, 'adminpuskesmas', $n), $puskesmas->id);
            if (! $admin->hasRole('admin_puskesmas')) {
                $admin->assignRole('admin_puskesmas');
            }
        }

        for ($n = 1; $n <= 5; $n++) {
            $tkUser = $this->makeUser("Tenaga Kesehatan {$namaPuskesmas} {$n}", $this->emailFor($namaPuskesmas, 'tenagakesehatan', $n), $puskesmas->id);
            if (! $tkUser->hasRole('tenaga_kesehatan')) {
                $tkUser->assignRole('tenaga_kesehatan');
            }
            TenagaKesehatan::firstOrCreate(
                ['user_id' => $tkUser->id],
                ['pj_id' => $pjId, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true, 'no_hp' => '08120000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)],
            );
        }

        for ($n = 1; $n <= 5; $n++) {
            $kaderUser = $this->makeUser("Kader {$namaPuskesmas} {$n}", $this->emailFor($namaPuskesmas, 'kader', $n), $puskesmas->id);
            if (! $kaderUser->hasRole('kader')) {
                $kaderUser->assignRole('kader');
            }
            Kader::firstOrCreate(
                ['user_id' => $kaderUser->id],
                ['pj_id' => $pjId, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true, 'no_hp' => '08130000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)],
            );
        }
    }

    private function findPuskesmas(string $namaPuskesmas): Puskesmas
    {
        $kodeInternal = 'PKM-'.strtoupper(str_replace(['-', ' '], '-', $namaPuskesmas));
        $puskesmas = Puskesmas::where('kode_internal', $kodeInternal)->first();

        if ($puskesmas === null) {
            throw new RuntimeException(
                "Puskesmas '{$namaPuskesmas}' (kode_internal {$kodeInternal}) tidak ditemukan -- pastikan data puskesmas sudah ada (restore dump produksi, atau jalankan PuskesmasSeeder) sebelum menjalankan SimulationUsersSeeder."
            );
        }

        return $puskesmas;
    }

    private function emailFor(string $namaPuskesmas, string $roleSlug, int $n): string
    {
        $slug = strtolower(str_replace('-', '', $namaPuskesmas));

        return "pkm.{$slug}.{$roleSlug}{$n}@gmail.com";
    }

    /**
     * Mirror persis TestAccountSeeder::makeUser() -- user yang SUDAH ADA sengaja tidak
     * ditimpa `name`-nya, cuma dipastikan password/puskesmas/verifikasinya bisa dipakai.
     */
    private function makeUser(string $name, string $email, ?int $puskesmasId): User
    {
        $attributes = [
            'password' => Hash::make(self::PASSWORD),
            'puskesmas_id' => $puskesmasId,
            'must_change_password' => false,
            'email_verified_at' => now(),
        ];

        $existing = User::where('email', $email)->first();

        if ($existing === null) {
            return User::create([...$attributes, 'email' => $email, 'name' => $name]);
        }

        $existing->update($attributes);

        return $existing->fresh();
    }
}
