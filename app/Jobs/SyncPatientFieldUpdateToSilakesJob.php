<?php

namespace App\Jobs;

use App\Exceptions\SilakesApiException;
use App\Models\PatientsCache;
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

        if ($patient === null || $this->fields === []) {
            return;
        }

        $payload = [
            ...$this->fields,
            'sumber' => 'kopipu_dashboard',
            'kopipu_diajukan_oleh' => $this->proposedByName,
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
