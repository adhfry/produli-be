<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Hapus patients_cache yang tidak (lagi) eligible -- tidak punya satupun baris
 * lab_results_cache (yang sejak SyncSilakesService::syncLabResults() sudah difilter ke
 * tanggal_periksa >= produli.silakes.lab_results_since). Pasien begini SENGAJA dikeluarkan
 * total dari PRODULI (keputusan produk), bukan cuma disembunyikan dari klasifikasi risiko.
 *
 * Pembersihan satu-arah dan eksplisit (bukan otomatis tiap sync) -- SyncSilakesService cuma
 * MENCEGAH pasien baru yang tidak eligible ikut ter-cache (lihat isEligible()), tidak
 * menghapus baris lama yang mungkin masih dipakai relasi lain. Command ini yang menghapus,
 * dan HANYA yang aman (tidak punya visit_assignments/risk_classifications terkait) --
 * baris yang masih punya relasi dilaporkan, bukan dipaksa hapus (FK constraint akan
 * menolak delete-nya juga kalau dipaksa).
 */
class PruneIneligiblePatientsCommand extends Command
{
    protected $signature = 'produli:prune-ineligible-patients
        {--dry-run : Tampilkan preview tanpa menghapus}';

    protected $description = 'Hapus pasien tercache yang tidak punya riwayat lab qualifying (dan tidak punya relasi aktif)';

    public function handle(): int
    {
        // DINONAKTIFKAN 2026-11-17: keputusan blueprint docs/planning/16 §9 no. 1 -- SEMUA pasien
        // Prolanis ditarik ke PRODULI, termasuk yang belum punya hasil lab. Perintah ini justru
        // menghapus pasien tanpa hasil lab, jadi menjalankannya akan menghapus data yang sah.
        // Logika lamanya ada di riwayat git bila suatu saat dibutuhkan lagi.
        $this->error('Perintah ini dinonaktifkan: sejak 2026-11-17 semua pasien Prolanis memang ditarik walau belum punya hasil lab (blueprint §9 no. 1). Tidak ada yang dihapus.');

        return self::FAILURE;
    }
}
