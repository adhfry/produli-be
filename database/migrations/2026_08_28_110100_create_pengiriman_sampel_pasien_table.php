<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris pasien dalam satu batch `pengiriman_sampel` (urutan antrian "meja A-B-C" yang bisa
 * di-drag/klik-ambil-urutan, lihat halaman dashboard/pengiriman-sampel).
 *
 * `external_patient_id` SENGAJA referensi LONGGAR (bukan FK DB) ke
 * `patients_cache.external_patient_id` -- sama konvensi dengan `lab_results_cache.patient_id`,
 * karena `patients_cache` murni cache read-only hasil sync SiLAKES (lihat SyncSilakesService),
 * bukan sumber kebenaran yang pas dijadikan FK constraint. Nullable karena baris ini bisa juga
 * merujuk pasien BARU yang belum ada di SiLAKES sama sekali (baru berupa usulan,
 * `registration_proposal_ref` terisi belakangan setelah batch dikirim ke SiLAKES di Fase D).
 *
 * `nama_snapshot`/`jenis_prolanis_snapshot` dibekukan saat baris ditambahkan ke antrian --
 * dipakai utk cetak PDF & render offline-safe, TIDAK ikut berubah kalau data pasien di
 * patients_cache diperbarui belakangan (foto kondisi pasien SAAT diantrikan, bukan data live).
 *
 * `urutan` kolom terpisah (bukan cuma urutan penyimpanan baris) -- perlu urutan PERSISTEN yang
 * race-safe utk drag-reorder (2 admin puskesmas bisa saja menyusun ulang urutan hampir
 * bersamaan), dijaga lewat unique constraint + `lockForUpdate()` di
 * PengirimanSampelService::reorder().
 *
 * Kolom `data_pasien_baru_*` (nik/gender/tempat_lahir/dst) HANYA terisi kalau `external_patient_id`
 * NULL -- staf puskesmas mengisi identitas pasien yang sama sekali belum ada di SiLAKES SAAT
 * menyusun antrian (Fase B), meski baris ini baru benar-benar DIKIRIM ke SiLAKES sebagai usulan
 * `patient_registration_proposals` nanti saat batch tiba di Labkesda (Fase D) -- field-nya
 * sengaja disamakan persis dengan tabel itu supaya tidak ada transformasi/pemetaan ulang saat
 * push, cukup salin apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengiriman_sampel_pasien', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengiriman_sampel_id')->constrained('pengiriman_sampel')->cascadeOnDelete();
            $table->unsignedBigInteger('external_patient_id')->nullable();
            $table->unsignedBigInteger('registration_proposal_ref')->nullable();
            $table->string('nama_snapshot');
            $table->enum('jenis_prolanis_snapshot', ['DM', 'HT', 'DM_HT'])->nullable();
            $table->unsignedInteger('urutan');

            // Identitas pasien baru (lihat docblock) -- nullable, cuma dipakai kalau
            // external_patient_id NULL.
            $table->string('data_pasien_baru_nik', 16)->nullable();
            $table->string('data_pasien_baru_gender', 1)->nullable();
            $table->string('data_pasien_baru_tempat_lahir')->nullable();
            $table->date('data_pasien_baru_tgl_lahir')->nullable();
            $table->string('data_pasien_baru_phone', 15)->nullable();
            $table->text('data_pasien_baru_alamat')->nullable();
            $table->string('data_pasien_baru_rt_rw', 50)->nullable();
            $table->string('data_pasien_baru_kel_desa')->nullable();
            $table->string('data_pasien_baru_kecamatan')->nullable();
            $table->string('data_pasien_baru_no_bpjs', 50)->nullable();

            $table->timestamps();

            $table->unique(['pengiriman_sampel_id', 'urutan']);
            $table->index('external_patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengiriman_sampel_pasien');
    }
};
