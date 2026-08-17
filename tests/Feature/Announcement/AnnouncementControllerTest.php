<?php

namespace Tests\Feature\Announcement;

use App\Models\AnnouncementRead;
use App\Models\SystemAnnouncement;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk Pengumuman Sistem (docs/planning/02 §13), REVISI (konten kaya + penargetan
 * role + tingkat urgensi + read-tracking untuk modal inbox login pertama) -- GET terbuka untuk
 * SEMUA role login termasuk tenaga_kesehatan (bug lama, sempat tidak disebut di Policy), tapi
 * daftar/unread SEKARANG di-scope ke target_roles. POST/DELETE cuma super_admin.
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

    // ---- GET index (feed, di-scope target_roles) ----

    public function test_semua_role_bisa_lihat_daftar_pengumuman_tanpa_target_roles(): void
    {
        SystemAnnouncement::create(['title' => 'Pemeliharaan Sistem', 'description' => 'Server akan down pukul 22.00.', 'urgency' => 'penting']);

        foreach (['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader', 'tenaga_kesehatan'] as $role) {
            Sanctum::actingAs($this->makeUser($role));

            $response = $this->getJson('/api/v1/announcements');

            $response->assertOk();
            $this->assertCount(1, $response->json('data.items'), "role {$role} seharusnya tetap lihat pengumuman tanpa target_roles (null = semua role).");
        }
    }

    public function test_daftar_pengumuman_di_scope_ke_target_roles(): void
    {
        SystemAnnouncement::create(['title' => 'Khusus Kader', 'description' => 'Info.', 'urgency' => 'info', 'target_roles' => ['kader']]);
        SystemAnnouncement::create(['title' => 'Global', 'description' => 'Info.', 'urgency' => 'info']);

        Sanctum::actingAs($this->makeUser('admin_puskesmas'));
        $response = $this->getJson('/api/v1/announcements');

        $response->assertOk();
        $titles = collect($response->json('data.items'))->pluck('title');
        $this->assertEqualsCanonicalizing(['Global'], $titles->all());
    }

    public function test_daftar_pengumuman_dual_role_cocok_kalau_salah_satu_target(): void
    {
        SystemAnnouncement::create(['title' => 'Khusus PJ', 'description' => 'Info.', 'urgency' => 'info', 'target_roles' => ['pj_prolanis']]);

        $user = User::factory()->create();
        $user->assignRole('pj_prolanis', 'kader');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/announcements');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_daftar_pengumuman_urutan_terbaru_dulu(): void
    {
        $lama = SystemAnnouncement::create(['title' => 'Lama', 'description' => 'Pengumuman lama.', 'urgency' => 'info']);
        DB::table('system_announcements')->where('id', $lama->id)->update(['created_at' => now()->subDay()]);
        $baru = SystemAnnouncement::create(['title' => 'Baru', 'description' => 'Pengumuman baru.', 'urgency' => 'info']);

        Sanctum::actingAs($this->makeUser('kader'));

        $response = $this->getJson('/api/v1/announcements');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$baru->id, $lama->id], $ids->all());
    }

    public function test_daftar_pengumuman_menyertakan_is_read(): void
    {
        $announcement = SystemAnnouncement::create(['title' => 'Info', 'description' => 'Info.', 'urgency' => 'info']);
        $user = $this->makeUser('kader');
        AnnouncementRead::create(['user_id' => $user->id, 'announcement_id' => $announcement->id, 'read_at' => now()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/announcements');

        $response->assertOk();
        $this->assertTrue($response->json('data.items.0.is_read'));
    }

    public function test_pengumuman_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/announcements')->assertStatus(401);
    }

    // ---- GET unread (modal inbox login pertama) ----

    public function test_unread_mengembalikan_pengumuman_yang_belum_dibaca_saja(): void
    {
        $sudahDibaca = SystemAnnouncement::create(['title' => 'Sudah Dibaca', 'description' => 'Info.', 'urgency' => 'info']);
        $belumDibaca = SystemAnnouncement::create(['title' => 'Belum Dibaca', 'description' => 'Info.', 'urgency' => 'darurat']);
        $user = $this->makeUser('kader');
        AnnouncementRead::create(['user_id' => $user->id, 'announcement_id' => $sudahDibaca->id, 'read_at' => now()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/announcements/unread');

        $response->assertOk();
        $titles = collect($response->json('data.items'))->pluck('title');
        $this->assertEqualsCanonicalizing(['Belum Dibaca'], $titles->all());
    }

    public function test_unread_di_scope_ke_target_roles_juga(): void
    {
        SystemAnnouncement::create(['title' => 'Khusus Nakes', 'description' => 'Info.', 'urgency' => 'info', 'target_roles' => ['tenaga_kesehatan']]);

        Sanctum::actingAs($this->makeUser('kader'));
        $response = $this->getJson('/api/v1/announcements/unread');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_unread_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/announcements/unread')->assertStatus(401);
    }

    // ---- POST {id}/read ----

    public function test_mark_read_mencatat_baris_announcement_reads(): void
    {
        $announcement = SystemAnnouncement::create(['title' => 'Info', 'description' => 'Info.', 'urgency' => 'info']);
        $user = $this->makeUser('kader');
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/announcements/{$announcement->id}/read");

        $response->assertOk();
        $this->assertDatabaseHas('announcement_reads', ['user_id' => $user->id, 'announcement_id' => $announcement->id]);
    }

    public function test_mark_read_dipanggil_dua_kali_idempotent(): void
    {
        $announcement = SystemAnnouncement::create(['title' => 'Info', 'description' => 'Info.', 'urgency' => 'info']);
        $user = $this->makeUser('kader');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/announcements/{$announcement->id}/read")->assertOk();
        $this->postJson("/api/v1/announcements/{$announcement->id}/read")->assertOk();

        $this->assertSame(1, AnnouncementRead::where('user_id', $user->id)->where('announcement_id', $announcement->id)->count());
    }

    // ---- POST (store) ----

    public function test_super_admin_berhasil_membuat_pengumuman(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'Libur Nasional',
            'description' => 'Layanan tutup tanggal 17 Agustus.',
            'urgency' => 'info',
        ]);

        $response->assertCreated();
        $this->assertSame('success', $response->json('status'));

        $announcement = SystemAnnouncement::first();
        $this->assertNotNull($announcement);
        $this->assertSame('Libur Nasional', $announcement->title);
        $this->assertSame('info', $announcement->urgency);
        $this->assertSame($superAdmin->id, $announcement->posted_by);
        $this->assertSame($superAdmin->id, $response->json('data.posted_by.id'));
    }

    public function test_super_admin_berhasil_membuat_pengumuman_dengan_konten_kaya(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'Fitur Baru',
            'description' => 'Ada fitur baru di aplikasi.',
            'urgency' => 'darurat',
            'icon' => 'LucideRocket',
            'color' => 'danger',
            'image_url' => 'https://example.com/banner.png',
            'button_label' => 'Lihat Detail',
            'button_url' => 'https://example.com/detail',
            'target_roles' => ['kader', 'tenaga_kesehatan'],
        ]);

        $response->assertCreated();
        $announcement = SystemAnnouncement::first();
        $this->assertSame('darurat', $announcement->urgency);
        $this->assertSame('LucideRocket', $announcement->icon);
        $this->assertSame('danger', $announcement->color);
        $this->assertSame('https://example.com/banner.png', $announcement->image_url);
        $this->assertSame('Lihat Detail', $announcement->button_label);
        $this->assertEqualsCanonicalizing(['kader', 'tenaga_kesehatan'], $announcement->target_roles);
    }

    public function test_non_super_admin_ditolak_membuat_pengumuman(): void
    {
        foreach (['admin_puskesmas', 'pj_prolanis', 'kader'] as $role) {
            Sanctum::actingAs($this->makeUser($role));

            $response = $this->postJson('/api/v1/announcements', [
                'title' => 'Coba Buat',
                'description' => 'Seharusnya ditolak.',
                'urgency' => 'info',
            ]);

            $response->assertStatus(403);
        }

        $this->assertSame(0, SystemAnnouncement::count());
    }

    public function test_urgency_wajib_salah_satu_dari_enum_yang_valid(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'Judul',
            'description' => 'Deskripsi.',
            'urgency' => 'panik',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, SystemAnnouncement::count());
    }

    public function test_title_dan_description_dan_urgency_wajib_diisi(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/announcements', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'description', 'urgency']);
    }

    public function test_button_label_wajib_kalau_button_url_diisi_dan_sebaliknya(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'Judul', 'description' => 'Deskripsi.', 'urgency' => 'info',
            'button_url' => 'https://example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['button_label']);
    }

    public function test_target_roles_ditolak_kalau_ada_role_tidak_valid(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'Judul', 'description' => 'Deskripsi.', 'urgency' => 'info',
            'target_roles' => ['super_admin', 'peran_ngasal'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['target_roles.1']);
    }

    public function test_buat_pengumuman_tanpa_login_ditolak_401(): void
    {
        $this->postJson('/api/v1/announcements', [])->assertStatus(401);
    }

    // ---- DELETE ----

    public function test_super_admin_bisa_hapus_pengumuman(): void
    {
        $announcement = SystemAnnouncement::create(['title' => 'Info', 'description' => 'Info.', 'urgency' => 'info']);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $this->deleteJson("/api/v1/announcements/{$announcement->id}")->assertOk();
        $this->assertDatabaseMissing('system_announcements', ['id' => $announcement->id]);
    }

    public function test_non_super_admin_ditolak_hapus_pengumuman(): void
    {
        $announcement = SystemAnnouncement::create(['title' => 'Info', 'description' => 'Info.', 'urgency' => 'info']);
        Sanctum::actingAs($this->makeUser('admin_puskesmas'));

        $this->deleteJson("/api/v1/announcements/{$announcement->id}")->assertStatus(403);
        $this->assertDatabaseHas('system_announcements', ['id' => $announcement->id]);
    }
}
