<?php

namespace Database\Seeders;

use App\Models\Desa;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\Puskesmas;
use App\Models\TenagaKesehatan;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitAssignmentCompanion;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * KHUSUS branch `dev`/lingkungan simulasi -- 1 pasien SINTETIS (BUKAN salah satu pasien
 * asli dari dump produksi) khusus untuk uji coba geofence GPS nyata (jalan kaki fisik)
 * sebelum presentasi ke Bu Kadis. Sengaja sintetis supaya lokasi rumah pasien SUNGGUHAN
 * tidak pernah dipakai sebagai titik uji coba publik.
 *
 * `external_patient_id` dipakai di rentang 900000001+ -- jauh di luar rentang ID asli
 * SiLAKES (integer biasa mulai dari kecil), supaya TIDAK PERNAH bentrok dengan data
 * pasien asli hasil restore dump.
 *
 * SENGAJA menolak jalan di APP_ENV=production (pola sama seperti SimulationUsersSeeder).
 */
class SimulationPatientsSeeder extends Seeder
{
    private const EXTERNAL_PATIENT_ID = 900000001;

    /**
     * Lokasi 1 dari 3 titik GPS nyata yang diberikan untuk uji coba (Kecamatan Kota
     * Sumenep) -- dipakai sebagai lokasi AWAL pasien (geo_status='approximate', radius
     * lebar 3000m). Lokasi 2 & 3 SENGAJA tidak disimpan sebagai baris database -- itu
     * titik jalan kaki manual saat demo, bukan bagian dari data pasien.
     *
     * Narasi demo (lihat docs/planning/14-setup-dev-simulasi-vps.md):
     * 1. Kunjungan pertama dari Lokasi 1 (atau sekitar Kota Sumenep mana pun, radius
     *    3000m) -- HARUS lolos, lalu submit dengan confirmed_patient_location=true.
     * 2. Setelah itu pasien otomatis geo_status='verified', radius mengetat ke 150m.
     * 3. Coba kunjungan BERIKUTNYA dari Lokasi 2 (~229m) atau Lokasi 3 (~660m) dari
     *    Lokasi 1 -- HARUS ditolak (di luar radius 150m), membuktikan pengetatan nyata.
     * 4. Coba dari Kecamatan Manding (~puluhan km) -- ditolak lebih jelas lagi di kedua
     *    tahap (approximate maupun verified).
     */
    private const LOKASI_1_LAT = -7.012297334521602;

    private const LOKASI_1_LNG = 113.85792322568487;

    // Referensi manual saja (lihat docblock kelas) -- TIDAK dipakai di kode.
    // Lokasi 2: -7.014265643897205, 113.85853187958793 (~229m dari Lokasi 1)
    // Lokasi 3: -7.014095639318136, 113.86361143575851 (~660m dari Lokasi 1)

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SimulationPatientsSeeder tidak boleh dijalankan di environment production.');
        }

        $puskesmas = Puskesmas::where('kode_internal', 'PKM-PANDIAN')->first();

        if ($puskesmas === null) {
            throw new RuntimeException('Puskesmas Pandian tidak ditemukan -- jalankan SimulationUsersSeeder/pastikan data puskesmas sudah ada dulu.');
        }

        $desaKota = Desa::whereHas('kecamatan', fn ($q) => $q->where('nama', 'Kota Sumenep'))->first();

        if ($desaKota === null) {
            throw new RuntimeException('Tidak ada baris desa untuk Kecamatan Kota Sumenep -- pastikan data wilayah (dump produksi atau MasterWilayahSeeder) sudah lengkap.');
        }

        $patient = PatientsCache::updateOrCreate(
            ['external_patient_id' => self::EXTERNAL_PATIENT_ID],
            [
                'no_reg' => 'SIMULASI-GPS-001',
                'nik_hash' => 'HASH-SIMULASI-GPS-KOTA-001',
                'nama' => '[SIMULASI] Pasien Uji GPS Kota Sumenep',
                'gender' => 'L',
                'tgl_lahir' => '1970-01-01',
                'phone' => '081200000000',
                'alamat' => 'Alamat simulasi -- bukan pasien sungguhan, khusus uji coba geofence GPS.',
                'rt_rw' => null,
                'kel_desa_raw' => $desaKota->nama,
                'kecamatan_raw' => 'Kota Sumenep',
                'is_prolanis' => true,
                'jenis_prolanis' => 'DM',
                'is_perokok' => false,
                'jenis_perokok' => null,
                'desa_id' => $desaKota->id,
                'kecamatan_id' => $desaKota->kecamatan_id,
                'wilayah_status' => 'resolved',
                'puskesmas_id' => $puskesmas->id,
                'puskesmas_resolution_method' => 'manual',
                'pengirim_raw' => null,
                'geo_status' => 'approximate',
                'latitude' => self::LOKASI_1_LAT,
                'longitude' => self::LOKASI_1_LNG,
                'geo_source' => null,
                'geo_verified_by' => null,
                'geo_verified_at' => null,
                'last_synced_at' => now(),
            ],
        );

        $tenagaKesehatan1 = TenagaKesehatan::whereHas(
            'user',
            fn ($q) => $q->where('email', 'pkm.pandian.tenagakesehatan1@gmail.com')
        )->first();
        $kader1 = Kader::whereHas(
            'user',
            fn ($q) => $q->where('email', 'pkm.pandian.kader1@gmail.com')
        )->first();

        if ($tenagaKesehatan1 === null || $kader1 === null) {
            throw new RuntimeException('Akun tenaga kesehatan/kader Puskesmas Pandian belum ada -- jalankan SimulationUsersSeeder dulu sebelum SimulationPatientsSeeder.');
        }

        $assignment = VisitAssignment::firstOrCreate(
            [
                'patient_id' => $patient->id,
                'tenaga_kesehatan_id' => $tenagaKesehatan1->id,
            ],
            [
                'assigned_by' => null,
                'scheduled_date' => now()->toDateString(),
                'status' => 'pending',
                'priority' => 'sedang',
                'assignment_method' => 'wilayah_resolved',
                'visit_origin' => 'manual',
                'puskesmas_id_snapshot' => $puskesmas->id,
            ],
        );

        VisitAssignmentCompanion::firstOrCreate([
            'assignment_id' => $assignment->id,
            'kader_id' => $kader1->id,
        ]);

        $this->command?->info("Selesai seed pasien simulasi GPS (external_patient_id=".self::EXTERNAL_PATIENT_ID.", assignment_id={$assignment->id}, tenaga_kesehatan=pkm.pandian.tenagakesehatan1@gmail.com, kader pendamping=pkm.pandian.kader1@gmail.com).");
    }

    /**
     * Dipakai command `produli:seed-simulation --reset-demo` -- kembalikan pasien &
     * assignment simulasi ke state AWAL (sebelum ada kunjungan apa pun), TANPA menyentuh
     * data dump/86 akun user. Cepat, aman diulang berkali-kali saat gladi bersih.
     */
    public static function resetDemoState(): void
    {
        $patient = PatientsCache::where('external_patient_id', self::EXTERNAL_PATIENT_ID)->first();

        if ($patient === null) {
            return;
        }

        $patient->update([
            'geo_status' => 'approximate',
            'latitude' => self::LOKASI_1_LAT,
            'longitude' => self::LOKASI_1_LNG,
            'geo_source' => null,
            'geo_verified_by' => null,
            'geo_verified_at' => null,
        ]);

        $assignments = VisitAssignment::where('patient_id', $patient->id)->get();

        foreach ($assignments as $assignment) {
            $assignment->visitReports()->delete();
            $assignment->update(['status' => 'pending']);
        }
    }
}
