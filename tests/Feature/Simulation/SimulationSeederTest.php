<?php

namespace Tests\Feature\Simulation;

use App\Models\Desa;
use App\Models\Kabupaten;
use App\Models\Kader;
use App\Models\Kecamatan;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use Database\Seeders\SimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi untuk lingkungan dev/simulasi (branch `dev` khusus) -- memastikan
 * SimulationSeeder menghasilkan 86 akun demo yang benar dan pasien uji GPS siap dipakai,
 * SEBELUM skrip ini pernah menyentuh VPS sungguhan. Setup di sini bikin
 * kabupaten/kecamatan/desa/puskesmas manual (pola sama seperti test lain di repo ini,
 * mis. KaderControllerTest) -- BUKAN lewat MasterWilayahSeeder (itu butuh API SiLAKES
 * live, tidak cocok untuk test otomatis).
 */
class SimulationSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $kabupaten = Kabupaten::create(['kode_kemendagri' => '35.29', 'nama' => 'Sumenep']);

        $kecKota = Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K01', 'nama' => 'Kota Sumenep']);
        Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K02', 'nama' => 'Manding']);
        Kecamatan::create(['kabupaten_id' => $kabupaten->id, 'kode_kemendagri' => 'K03', 'nama' => 'Gapura']);

        Desa::create(['kecamatan_id' => $kecKota->id, 'kode_kemendagri' => 'D-KOTA-1', 'nama' => 'Desa Kota 1']);

        Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-MANDING', 'nama' => 'Puskesmas Manding']);
        Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-GAPURA', 'nama' => 'Puskesmas Gapura']);
        Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-PANDIAN', 'nama' => 'Puskesmas Pandian']);
        Puskesmas::create(['kabupaten_id' => $kabupaten->id, 'kode_internal' => 'PKM-PAMOLOKAN', 'nama' => 'Puskesmas Pamolokan']);
    }

    public function test_simulation_seeder_membuat_86_akun_demo(): void
    {
        $this->seed(SimulationSeeder::class);

        // 5 superadminN@gmail.com + ahda.creator@gmail.com = 6.
        $this->assertSame(6, User::role('super_admin')->count());

        foreach (['manding', 'gapura', 'pandian', 'pamolokan'] as $slug) {
            $this->assertSame(5, User::role('admin_puskesmas')->where('email', 'like', "pkm.{$slug}.adminpuskesmas%")->count(), "admin_puskesmas {$slug}");
            $this->assertSame(5, User::role('pj_prolanis')->where('email', 'like', "pkm.{$slug}.pjprolanis%")->count(), "pj_prolanis {$slug}");
            $this->assertSame(5, User::role('tenaga_kesehatan')->where('email', 'like', "pkm.{$slug}.tenagakesehatan%")->count(), "tenaga_kesehatan {$slug}");
            $this->assertSame(5, User::role('kader')->where('email', 'like', "pkm.{$slug}.kader%")->count(), "kader {$slug}");
        }

        // Kader tambahan (email asli, bukan pola pkm.*).
        $extraKader = User::where('email', 'ahdafirlybarori@gmail.com')->first();
        $this->assertNotNull($extraKader);
        $this->assertTrue($extraKader->hasRole('kader'));
        $this->assertNotNull(Kader::where('user_id', $extraKader->id)->first());

        $pandian = Puskesmas::where('kode_internal', 'PKM-PANDIAN')->first();
        $this->assertSame($pandian->id, $extraKader->puskesmas_id);

        // Password sama untuk semua (spot-check 1 akun).
        $superAdmin1 = User::where('email', 'superadmin1@gmail.com')->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('12345678', $superAdmin1->password));
        $this->assertFalse($superAdmin1->must_change_password);
    }

    public function test_simulation_seeder_membuat_tenaga_kesehatan_dan_kader_profil(): void
    {
        $this->seed(SimulationSeeder::class);

        $pandian = Puskesmas::where('kode_internal', 'PKM-PANDIAN')->first();

        $tkUser = User::where('email', 'pkm.pandian.tenagakesehatan1@gmail.com')->first();
        $tk = TenagaKesehatan::where('user_id', $tkUser->id)->first();
        $this->assertNotNull($tk);
        $this->assertTrue($tk->status_aktif);
        $this->assertSame($pandian->id, $tk->puskesmas_id);

        $pj1 = User::where('email', 'pkm.pandian.pjprolanis1@gmail.com')->first();
        $this->assertSame($pj1->id, $tk->pj_id);
    }

    public function test_simulation_seeder_membuat_pasien_uji_gps_dan_assignment(): void
    {
        $this->seed(SimulationSeeder::class);

        $patient = PatientsCache::where('external_patient_id', 900000001)->first();
        $this->assertNotNull($patient);
        $this->assertSame('approximate', $patient->geo_status);
        $this->assertEqualsWithDelta(-7.012297334521602, (float) $patient->latitude, 0.0000001);
        $this->assertEqualsWithDelta(113.85792322568487, (float) $patient->longitude, 0.0000001);

        $assignment = VisitAssignment::where('patient_id', $patient->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame('pending', $assignment->status);
        $this->assertNull($assignment->kader_id);
        $this->assertNotNull($assignment->tenaga_kesehatan_id);

        $this->assertSame(1, $assignment->companions()->count());
    }

    public function test_seeder_idempoten_dijalankan_dua_kali(): void
    {
        $this->seed(SimulationSeeder::class);
        $countBefore = User::count();

        $this->seed(SimulationSeeder::class);

        $this->assertSame($countBefore, User::count());
        $this->assertSame(1, PatientsCache::where('external_patient_id', 900000001)->count());
        $this->assertSame(1, VisitAssignment::where('patient_id', PatientsCache::where('external_patient_id', 900000001)->first()->id)->count());
    }

    public function test_reset_demo_mengembalikan_pasien_ke_state_awal(): void
    {
        $this->seed(SimulationSeeder::class);

        $patient = PatientsCache::where('external_patient_id', 900000001)->first();
        $assignment = VisitAssignment::where('patient_id', $patient->id)->first();

        // Simulasikan kunjungan sudah terjadi -- pasien jadi verified + assignment completed.
        $patient->update([
            'geo_status' => 'verified',
            'latitude' => -7.0140,
            'longitude' => 113.8600,
            'geo_source' => 'kader_verified',
        ]);
        $assignment->update(['status' => 'completed']);
        VisitReport::create([
            'assignment_id' => $assignment->id,
            'gps_lat' => -7.0140,
            'gps_lng' => 113.8600,
            'photo_path' => 'visit-photos/simulasi-test.jpg',
            'kondisi' => 'stabil',
            'geo_status' => 'verified',
            'sync_status' => 'pending',
        ]);

        $this->artisan('produli:seed-simulation', ['--reset-demo' => true])->assertSuccessful();

        $patient->refresh();
        $assignment->refresh();

        $this->assertSame('approximate', $patient->geo_status);
        $this->assertEqualsWithDelta(-7.012297334521602, (float) $patient->latitude, 0.0000001);
        $this->assertNull($patient->geo_source);
        $this->assertSame('pending', $assignment->status);
        $this->assertSame(0, $assignment->visitReports()->count());

        // Akun demo TIDAK ikut terhapus/berubah oleh --reset-demo.
        $this->assertSame(6, User::role('super_admin')->count());
    }
}
