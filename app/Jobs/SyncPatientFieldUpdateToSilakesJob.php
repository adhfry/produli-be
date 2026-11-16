<?php

namespace App\Jobs;

use App\Exceptions\SilakesApiException;
use App\Models\PatientsCache;
use App\Services\Ktp\KtpScanService;
use App\Services\Silakes\SilakesApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push usulan koreksi data pasien dari STAF (admin_puskesmas/pj_prolanis lewat dashboard/pasien,
 * "Ajukan Update Data") ke SiLAKES -- jalur PARALEL dari SyncFieldUpdateToSilakesJob (itu khusus
 * usulan kader yang terikat satu laporan kunjungan spesifik, visit_reports.sync_status). Job ini
 * TIDAK terikat model manapun (tidak ada "laporan" di alur staf ini) -- kegagalan/retry dicatat
 * lewat mekanisme queue bawaan Laravel (failed_jobs), bukan kolom sync_status khusus.
 */
class SyncPatientFieldUpdateToSilakesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Foto KTP hasil scan (revisi 2026-11-16). Properti biasa dengan default -- BUKAN promoted
     * property -- supaya job yang sudah antre sebelum deploy tetap bisa di-unserialize.
     * Hanya id yang disimpan di antrian; isi foto dibaca saat job jalan.
     */
    public ?int $ktpScanId = null;

    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly int $patientId,
        public readonly array $fields,
        public readonly ?string $proposedByName = null,
    ) {}

    /**
     * Backoff menaik: 1m, 5m, 15m, 1j, 4j -- sama persis dengan SyncFieldUpdateToSilakesJob,
     * SiLAKES down sebentar tidak perlu diserbu retry berkali-kali dalam semenit.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function handle(SilakesApiClient $client): void
    {
        $patient = PatientsCache::find($this->patientId);

        $fotoKtp = app(KtpScanService::class)->base64Of($this->ktpScanId);

        if ($patient === null || ($this->fields === [] && $fotoKtp === null)) {
            return;
        }

        $payload = [
            ...$this->fields,
            // produli_ktp: alamat yang diusulkan berasal dari KTP, jadi SiLAKES menulisnya ke
            // alamat KTP pasien (bukan domisili) saat disetujui.
            'sumber' => $this->ktpScanId ? 'produli_ktp' : 'produli_dashboard',
            ...($fotoKtp ? ['foto_ktp_base64' => $fotoKtp] : []),
            // SiLAKES (IntegrationController::submitFieldUpdate) HANYA membaca
            // 'produli_kader_nama' dari request -- pakai key yang sama di sini (bukan
            // 'produli_diajukan_oleh') supaya nama pengaju SUNGGUHAN tersimpan, bukan
            // dibuang diam-diam karena field tidak dikenali. Nama kolomnya di SiLAKES
            // sejarahnya untuk kader, tapi sekarang dipakai bersama utk pengaju staf
            // dashboard juga -- lihat SUMBER_META di PatientFieldUpdates Vue (SiLAKES)
            // yang membedakan sumber kunjungan vs dashboard lewat kolom 'sumber', bukan
            // dari nama kolom pengaju.
            'produli_kader_nama' => $this->proposedByName,
        ];

        try {
            $client->postPembaruanLapangan($patient->external_patient_id, $payload);
        } catch (SilakesApiException $e) {
            if ($e->isClientError()) {
                // 401/403/422 dst -- tidak akan membaik dengan retry, gagalkan langsung.
                $this->fail($e);

                return;
            }

            throw $e; // 5xx/lainnya -> biar Laravel retry sesuai backoff()
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Gagal push usulan update data pasien (dashboard) ke SiLAKES', [
            'patient_id' => $this->patientId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
