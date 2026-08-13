<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk Profil Saya & Pengaturan, semua role (docs/planning/02 §17) --
 * POST /api/v1/auth/profile/avatar (upload S3/MinIO) dan PATCH /api/v1/auth/profile
 * (email_notifications_enabled). Selalu beroperasi di profil user yang login sendiri.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        Storage::fake('s3');
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('kader');

        return $user;
    }

    // ---- Upload avatar ----

    public function test_upload_avatar_berhasil(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('foto.jpg', 300, 300),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->avatar_path);
        $this->assertStringStartsWith('profile/'.$user->id.'/', $fresh->avatar_path);
        Storage::disk('s3')->assertExists($fresh->avatar_path);
        $this->assertSame($fresh->avatar_path, $response->json('data.user.avatar_path'));
    }

    public function test_upload_avatar_menghapus_avatar_lama(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->post('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('lama.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $avatarLama = $user->fresh()->avatar_path;
        Storage::disk('s3')->assertExists($avatarLama);

        $this->post('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('baru.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $avatarBaru = $user->fresh()->avatar_path;

        $this->assertNotSame($avatarLama, $avatarBaru);
        Storage::disk('s3')->assertMissing($avatarLama);
        Storage::disk('s3')->assertExists($avatarBaru);
    }

    public function test_upload_avatar_wajib_file_gambar(): void
    {
        Sanctum::actingAs($this->makeUser());

        $response = $this->post('/api/v1/auth/profile/avatar', [], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }

    public function test_upload_avatar_tanpa_login_ditolak_401(): void
    {
        $this->post('/api/v1/auth/profile/avatar', [], ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_upload_avatar_diblokir_selagi_must_change_password_true(): void
    {
        $user = User::factory()->create(['password' => Hash::make('x'), 'must_change_password' => true]);
        $user->assignRole('kader');
        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('foto.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(403);
    }

    // ---- Update profile (email_notifications_enabled) ----

    public function test_update_profile_berhasil_matikan_email_notifications(): void
    {
        $user = $this->makeUser();
        // Nilai default kolom (true) baru kebaca kalau di-fresh() dari DB -- instance in-memory
        // hasil create() tidak otomatis tahu default level-DB yang tidak eksplisit di-assign.
        $this->assertTrue($user->fresh()->email_notifications_enabled);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/auth/profile', [
            'email_notifications_enabled' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($user->fresh()->email_notifications_enabled);
        $this->assertFalse($response->json('data.user.email_notifications_enabled'));
    }

    public function test_update_profile_tanpa_field_apa_pun_tetap_ok(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/auth/profile', []);

        $response->assertOk();
        $this->assertTrue($user->fresh()->email_notifications_enabled);
    }

    public function test_update_profile_tanpa_login_ditolak_401(): void
    {
        $this->patchJson('/api/v1/auth/profile', ['email_notifications_enabled' => false])
            ->assertStatus(401);
    }

    /**
     * Regresi bug nyata: no_wa/alamat/gender/tgl_lahir cuma bisa diisi SEKALI saat onboarding
     * (CompleteOnboardingRequest) -- tidak ada jalan untuk mengedit lagi setelahnya, halaman
     * /dashboard/profil juga tidak pernah menampilkannya sama sekali. Endpoint ini dipakai staf
     * (admin_puskesmas/pj_prolanis/super_admin/tenaga_kesehatan) -- kader punya halaman profil
     * terpisah lewat PATCH /kader/profile ke tabel kader, bukan endpoint ini.
     */
    public function test_update_profile_bisa_ubah_field_yang_dulu_hanya_bisa_diisi_saat_onboarding(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin_puskesmas');
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/auth/profile', [
            'no_wa' => '081234567890',
            'alamat' => 'Jl. Contoh No. 1',
            'gender' => 'L',
            'tgl_lahir' => '1990-05-15',
        ]);

        $response->assertOk();
        $fresh = $user->fresh();
        $this->assertSame('081234567890', $fresh->no_wa);
        $this->assertSame('Jl. Contoh No. 1', $fresh->alamat);
        $this->assertSame('L', $fresh->gender);
        $this->assertSame('1990-05-15', $fresh->tgl_lahir->toDateString());
        $this->assertSame('081234567890', $response->json('data.user.no_wa'));
    }

    public function test_update_profile_gender_tidak_valid_ditolak_422(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/auth/profile', ['gender' => 'X']);

        $response->assertStatus(422);
    }

    public function test_update_profile_diblokir_selagi_must_change_password_true(): void
    {
        $user = User::factory()->create(['password' => Hash::make('x'), 'must_change_password' => true]);
        $user->assignRole('kader');
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/auth/profile', ['email_notifications_enabled' => false]);

        $response->assertStatus(403);
    }
}
