<?php

namespace Tests\Feature\Realtime;

use App\Models\Kabupaten;
use App\Models\Puskesmas;
use App\Models\User;
use App\Services\Realtime\WebsocketTokenService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Format token WAJIB cocok persis dengan yang diverifikasi ProduliWss.Auth.Token di sisi
 * Phoenix (produli-wss/lib/produli_wss/auth/token.ex) -- test ini decode manual pakai
 * algoritma yang SAMA (HMAC-SHA256 payload mentah, base64url tanpa padding) supaya perubahan
 * yang memecah kontrak ketahuan di sini, bukan baru ketahuan saat frontend gagal connect.
 */
class WebsocketTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private WebsocketTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WebsocketTokenService::class);
        $this->seed(RolesSeeder::class);
        config(['produli.realtime.token_secret' => 'test-token-secret']);
    }

    private function decode(string $token, string $secret): array
    {
        [$payloadB64, $sigB64] = explode('.', $token, 2);
        $payloadRaw = base64_decode(strtr($payloadB64, '-_', '+/'));
        $sig = base64_decode(strtr($sigB64, '-_', '+/'));

        $expected = hash_hmac('sha256', $payloadRaw, $secret, true);
        $this->assertTrue(hash_equals($expected, $sig), 'signature tidak valid');

        return json_decode($payloadRaw, true);
    }

    public function test_token_berisi_uid_role_pid_exp_untuk_admin_puskesmas(): void
    {
        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);
        $puskesmas = Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-A', 'nama' => 'Puskesmas A']);
        $user = User::factory()->create(['puskesmas_id' => $puskesmas->id]);
        $user->assignRole('admin_puskesmas');

        $token = $this->service->issueFor($user);
        $claims = $this->decode($token, 'test-token-secret');

        $this->assertSame($user->id, $claims['uid']);
        $this->assertSame('admin_puskesmas', $claims['role']);
        $this->assertSame($puskesmas->id, $claims['pid']);
        $this->assertGreaterThan(now()->timestamp, $claims['exp']);
    }

    public function test_pid_null_untuk_super_admin(): void
    {
        $user = User::factory()->create(['puskesmas_id' => null]);
        $user->assignRole('super_admin');

        $claims = $this->decode($this->service->issueFor($user), 'test-token-secret');

        $this->assertSame('super_admin', $claims['role']);
        $this->assertNull($claims['pid']);
    }

    /**
     * super_admin > admin_puskesmas/pj_prolanis > tenaga_kesehatan > kader -- kalau (secara
     * teoretis) user punya >1 role, role realtime yang dipakai HARUS deterministik & konsisten
     * dengan role paling berwenang, bukan urutan random dari hasRole().
     */
    public function test_role_dominan_super_admin_menang_walau_juga_admin_puskesmas(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['admin_puskesmas', 'super_admin']);

        $claims = $this->decode($this->service->issueFor($user), 'test-token-secret');

        $this->assertSame('super_admin', $claims['role']);
    }

    public function test_gagal_kalau_secret_belum_dikonfigurasi(): void
    {
        config(['produli.realtime.token_secret' => '']);
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->service->issueFor($user);
    }
}
