<?php

namespace App\Jobs;

use App\Exceptions\SilakesApiException;
use App\Models\VisitReport;
use App\Services\Silakes\SilakesApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Push balik geo-verifikasi kader ke SiLAKES (POST .../pembaruan-lapangan, docs/planning/01 §9).
 * Job TERPISAH dengan retry — dipanggil SETELAH laporan kunjungan tersimpan lokal
 * (VisitReportService), jadi kegagalan/timeout di sini TIDAK PERNAH menggagalkan submit
 * laporan kader (docs/planning/02 §2c).
 */
class SyncFieldUpdateToSilakesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $visitReportId) {}

    /**
     * Backoff menaik: 1m, 5m, 15m, 1j, 4j — SiLAKES down sebentar tidak perlu diserbu retry
     * berkali-kali dalam semenit, tapi juga tidak menyerah dalam hitungan menit kalau downtime lama.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function handle(SilakesApiClient $client): void
    {
        $report = VisitReport::with(['assignment.patient', 'assignment.kader.user'])->find($this->visitReportId);

        if (! $report || $report->sync_status === 'synced') {
            return; // laporan sudah tidak ada, atau sudah pernah sukses (guard idempotency)
        }

        $patient = $report->assignment->patient;
        $kader = $report->assignment->kader;

        $payload = array_filter([
            'latitude' => $report->latitude !== null ? (float) $report->latitude : null,
            'longitude' => $report->longitude !== null ? (float) $report->longitude : null,
            // Usulan kontak/alamat/identitas (docs/planning/01 §9) yang dilengkapi kader saat
            // kunjungan -- disimpan mentah di visit_reports.patient_field_updates, di-relay apa
            // adanya di sini (SiLAKES yang menentukan field mana yang dikenali/divalidasi).
            ...($report->patient_field_updates ?? []),
            'sumber' => 'kopipu_kunjungan',
            'kopipu_visit_id' => $report->id,
            'kopipu_kader_nama' => $kader?->user?->name,
        ], fn ($value) => $value !== null);

        try {
            $client->postPembaruanLapangan($patient->external_patient_id, $payload);
        } catch (SilakesApiException $e) {
            if ($e->isClientError()) {
                // 401/403/422 dst — tidak akan membaik dengan retry, gagalkan langsung.
                $this->fail($e);

                return;
            }

            throw $e; // 5xx/lainnya -> biar Laravel retry sesuai backoff()
        }

        $report->update(['sync_status' => 'synced', 'synced_at' => now(), 'sync_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        VisitReport::whereKey($this->visitReportId)->update([
            'sync_status' => 'failed',
            'sync_error' => $exception?->getMessage(),
        ]);
    }
}
