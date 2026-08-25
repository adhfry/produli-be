<?php

namespace Tests\Feature\Realtime;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['produli.realtime.token_secret' => 'test-token-secret']);
        $this->seed(RolesSeeder::class);
    }

    public function test_user_login_dapat_token_socket(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now(), 'must_change_password' => false]);
        $user->assignRole('admin_puskesmas');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/ws-token');

        $response->assertOk();
        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);
    }

    public function test_tanpa_login_ditolak_401(): void
    {
        $response = $this->getJson('/api/v1/ws-token');

        $response->assertUnauthorized();
    }
}
