<?php
namespace Database\Seeders;

use App\Models\Kader;
use App\Models\Puskesmas;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * 4 akun tes -- 1 per role (super_admin/admin_puskesmas/pj_prolanis/kader), password dikenal,
 * `must_change_password=false` supaya langsung bisa dipakai tanpa lewat alur ganti password.
 * kader dapat baris `kader` terkait (bukan cuma `users`), disupervisi oleh akun pj_prolanis
 * yang sama-sama di-seed di sini -- supaya alur accept laporan (docs/planning/02 §11) juga
 * langsung bisa dites.
 *
 * `onboarding_completed_at` (docs/planning/02 §14) SENGAJA TIDAK diisi -- kolom itu belum ada
 * di skema `users` (§14 baru rencana: belum ada migration, endpoint
 * POST /api/v1/auth/onboarding/complete, maupun middleware gate-nya). Tidak ada yang
 * memblokir akun ini saat ini karena gate itu memang belum dibangun; kalau §14 nanti
 * diimplementasikan, tambahkan satu baris update di sini.
 *
 * KHUSUS environment lokal/dev -- TIDAK didaftarkan di DatabaseSeeder::run(), jalankan manual:
 *   php artisan db:seed --class=TestAccountSeeder
 * Sengaja menolak jalan di APP_ENV=production (password dikenal publik lewat riwayat repo/chat).
 */
class TestAccountSeeder extends Seeder
{
    private const PASSWORD = '12345678';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('TestAccountSeeder tidak boleh dijalankan di environment production.');
        }

        $this->call(RolesSeeder::class);

        $puskesmas = Puskesmas::first();

        if ($puskesmas === null) {
            throw new RuntimeException(
                'Tidak ada baris di tabel puskesmas -- seed/buat puskesmas dulu (mis. lewat MasterWilayahSeeder untuk kabupaten/kecamatan/desa, lalu insert puskesmas manual, docs/planning/02 §2a) sebelum menjalankan TestAccountSeeder.'
            );
        }

        // PENTING: ganti email ini kalau bukan Anda -- akun super_admin dipakai untuk login
        // Google sekaligus email/password (docs/planning/02, alur auth).
        $superAdmin = $this->makeUser('Ahda Firly Barori', 'ahda.creator@gmail.com', null);
        $superAdmin->assignRole('super_admin');

        // $adminPuskesmas = $this->makeUser('Admin Puskesmas Uji', 'admin.puskesmas.uji@example.test', $puskesmas->id);
        // $adminPuskesmas->assignRole('admin_puskesmas');

        // $pjProlanis = $this->makeUser('PJ Prolanis Uji', 'pj.prolanis.uji@example.test', $puskesmas->id);
        // $pjProlanis->assignRole('pj_prolanis');

        // $kaderUser = $this->makeUser('Kader Uji', 'kader.uji@example.test', $puskesmas->id);
        // $kaderUser->assignRole('kader');

        // Kader::firstOrCreate(
        //     ['user_id' => $kaderUser->id],
        //     ['pj_id' => $pjProlanis->id, 'puskesmas_id' => $puskesmas->id, 'status_aktif' => true],
        // );

        // $this->printCredentials($puskesmas);
    }

    /**
     * User yang SUDAH ADA (mis. akun asli Anda sendiri, didaftarkan sebelum seeder ini pernah
     * jalan) SENGAJA tidak ikut ditimpa `name`-nya -- cuma dipastikan password/puskesmas/
     * verifikasinya bisa dipakai untuk testing. `name` default cuma dipakai untuk user BARU.
     */
    private function makeUser(string $name, string $email, ?int $puskesmasId): User
    {
        $attributes = [
            'password'             => Hash::make(self::PASSWORD),
            'puskesmas_id'         => $puskesmasId,
            'must_change_password' => false,
            'email_verified_at'    => now(),
        ];

        $existing = User::where('email', $email)->first();

        if ($existing === null) {
            return User::create([ ...$attributes, 'email' => $email, 'name' => $name]);
        }

        $existing->update($attributes);

        return $existing->fresh();
    }

    private function printCredentials(Puskesmas $puskesmas): void
    {
        $this->command?->info('');
        $this->command?->info("=== Akun tes TestAccountSeeder (puskesmas: {$puskesmas->nama}, id={$puskesmas->id}) ===");
        $this->command?->table(
            ['Role', 'Email', 'Password'],
            [
                ['super_admin', 'ahda.creator@gmail.com', self::PASSWORD],
            ],
        );
        $this->command?->warn('Simpan kredensial ini -- password sama untuk semua akun, khusus dev/lokal.');
    }
}
