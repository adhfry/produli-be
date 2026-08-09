<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Notifications\VisitReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi untuk GET /api/v1/notifications dan PATCH /api/v1/notifications/{id}/read
 * (docs/planning/02 §8) -- tanpa ini, reminder yang dibuat NotificationService/DatabaseReminderChannel
 * tidak pernah bisa diambil frontend. Notifikasi dibuat langsung lewat relasi notifications()
 * bawaan Laravel (bukan lewat VisitReminderNotification penuh) -- controller ini generik,
 * tidak peduli tipe notifikasinya apa.
 */
class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeNotification(User $user, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create(array_merge([
            'id' => $id,
            'type' => VisitReminderNotification::class,
            'data' => ['type' => 'visit_reminder', 'assignment_id' => 1, 'patient_nama' => 'Pasien Uji'],
            'read_at' => null,
        ], $overrides));

        return $id;
    }

    public function test_user_melihat_notifikasi_miliknya_sendiri_terurut_terbaru_dulu(): void
    {
        $user = User::factory()->create();
        $userLain = User::factory()->create();

        $lama = $this->makeNotification($user);
        DB::table('notifications')->where('id', $lama)->update(['created_at' => now()->subMinutes(10)]);
        $baru = $this->makeNotification($user);
        $this->makeNotification($userLain);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$baru, $lama], $ids->all());
    }

    public function test_filter_unread(): void
    {
        $user = User::factory()->create();
        $sudahDibaca = $this->makeNotification($user, ['read_at' => now()]);
        $belumDibaca = $this->makeNotification($user);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications?unread=1');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertEquals([$belumDibaca], $ids->all());
        $this->assertNotContains($sudahDibaca, $ids->all());
    }

    public function test_unread_count_disertakan_di_response(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user);
        $this->makeNotification($user);
        $this->makeNotification($user, ['read_at' => now()]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.unread_count'));
    }

    public function test_mark_as_read_berhasil(): void
    {
        $user = User::factory()->create();
        $id = $this->makeNotification($user);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/notifications/{$id}/read");

        $response->assertOk();
        $this->assertTrue($response->json('data.is_read'));
        $this->assertNotNull($response->json('data.read_at'));

        $this->assertNotNull($user->notifications()->find($id)->read_at);
    }

    public function test_mark_as_read_idempotent_kalau_sudah_dibaca(): void
    {
        $user = User::factory()->create();
        $id = $this->makeNotification($user, ['read_at' => now()->subMinutes(5)]);
        $readAtSebelum = $user->notifications()->find($id)->read_at;

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/notifications/{$id}/read");

        $response->assertOk();
        $this->assertTrue($readAtSebelum->equalTo($user->notifications()->find($id)->read_at));
    }

    public function test_user_tidak_bisa_menandai_notifikasi_user_lain(): void
    {
        $user = User::factory()->create();
        $userLain = User::factory()->create();
        $idMilikLain = $this->makeNotification($userLain);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/notifications/{$idMilikLain}/read");

        $response->assertStatus(404);
        $this->assertNull($userLain->notifications()->find($idMilikLain)->read_at);
    }

    public function test_pagination(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->makeNotification($user);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications?per_page=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertSame(2, $response->json('data.pagination.last_page'));
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->patchJson('/api/v1/notifications/'.Str::uuid().'/read')->assertStatus(401);
    }
}
