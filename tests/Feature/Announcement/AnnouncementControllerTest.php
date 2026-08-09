<?php

namespace Tests\Feature\Announcement;

use App\Models\SystemAnnouncement;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk Pengumuman Sistem (docs/planning/02 §13) -- GET terbuka untuk semua role
 * login (tidak ada scoping puskesmas/kader, pengumuman selalu global), POST cuma super_admin.
 */
class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // ---- GET ----

    public function test_semua_role_bisa_lihat_daftar_pengumuman(): void
    {
        SystemAnnouncement::create(['title' => 'Pemeliharaan Sistem', 'description' => 'Server akan down pukul 22.00.', 'type' => 'warning']);

        foreach (['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader'] as $role) {
            Sanctum::actingAs($this->makeUser($role));

            $response = $this->getJson('/api/v1/announcements');

            $response->assertOk();
            $this->assertCount(1, $response->json('data.items'));
        }
    }

    public function test_daftar_pengumuman_urutan_terbaru_dulu(): void
    {
        $lama = SystemAnnouncement::create(['title' => 'Lama', 'description' => 'Pengumuman lama.', 'type' => 'info']);
        DB::table('system_announcements')->where('id', $lama->id)->update(['created_at' => now()->subDay()]);
        $baru = SystemAnnouncement::create(['title' => 'Baru', 'description' => 'Pengumuman baru.', 'type' => 'info']);

        Sanctum::actingAs($this->makeUser('kader'));

        $response = $this->getJson('/api/v1/announcements');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$baru->id, $lama->id], $ids->all());
    }

    public function test_pengumuman_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/announcements')->assertStatus(401);
    }

    // ---- POST ----

    public function test_super_admin_berhasil_membuat_pengumuman(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'Libur Nasional',
            'description' => 'Layanan tutup tanggal 17 Agustus.',
            'type' => 'info',
        ]);

        $response->assertCreated();
        $this->assertSame('success', $response->json('status'));

        $announcement = SystemAnnouncement::first();
        $this->assertNotNull($announcement);
        $this->assertSame('Libur Nasional', $announcement->title);
        $this->assertSame('info', $announcement->type);
        $this->assertSame($superAdmin->id, $announcement->posted_by);
        $this->assertSame($superAdmin->id, $response->json('data.posted_by.id'));
    }

    public function test_non_super_admin_ditolak_membuat_pengumuman(): void
    {
        foreach (['admin_puskesmas', 'pj_prolanis', 'kader'] as $role) {
            Sanctum::actingAs($this->makeUser($role));

            $response = $this->postJson('/api/v1/announcements', [
                'title' => 'Coba Buat',
                'description' => 'Seharusnya ditolak.',
                'type' => 'info',
            ]);

            $response->assertStatus(403);
        }

        $this->assertSame(0, SystemAnnouncement::count());
    }

    public function test_type_wajib_salah_satu_dari_enum_yang_valid(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'Judul',
            'description' => 'Deskripsi.',
            'type' => 'danger',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, SystemAnnouncement::count());
    }

    public function test_title_dan_description_wajib_diisi(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/announcements', ['type' => 'info']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'description']);
    }

    public function test_buat_pengumuman_tanpa_login_ditolak_401(): void
    {
        $this->postJson('/api/v1/announcements', [])->assertStatus(401);
    }
}
