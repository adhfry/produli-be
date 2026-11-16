<?php

namespace App\Console\Commands;

use App\Exceptions\SilakesApiException;
use App\Models\PengirimanSampelPasien;
use App\Services\Silakes\SilakesApiClient;
use App\Services\Silakes\SilakesEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cadangan pendaftaran langsung (revisi 2026-11-17): bila event "disetujui/ditolak" dari SiLAKES
 * tidak sampai (jaringan, PRODULI sedang deploy), pendaftaran yang masih menunggu verifikasi
 * dicek statusnya di sini. Hasilnya diterapkan lewat SilakesEventService yang sama dengan event,
 * jadi pengaju tetap dinotifikasi tepat sekali.
 */
class CekPendaftaranSilakesCommand extends Command
{
    protected $signature = 'produli:cek-pendaftaran-silakes';

    protected $description = 'Cek status pendaftaran pasien baru yang masih menunggu verifikasi Labkesda';

    public function handle(SilakesApiClient $client, SilakesEventService $events): int
    {
        $baris = PengirimanSampelPasien::where('registrasi_status', 'menunggu_verifikasi')
            ->whereNotNull('registration_proposal_ref')
            ->where('registrasi_diperbarui_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit(100)
            ->get();

        $diputuskan = 0;
        foreach ($baris as $b) {
            try {
                $data = $client->getPatientRegistration((int) $b->registration_proposal_ref)['data'] ?? [];
            } catch (SilakesApiException $e) {
                Log::warning('Gagal cek status pendaftaran pasien baru', ['baris_id' => $b->id, 'error' => $e->getMessage()]);

                continue;
            }

            if (in_array($data['status'] ?? null, ['approved', 'rejected'], true)) {
                $events->terapkanPendaftaran($data, $b);
                $diputuskan++;
            }
        }

        $this->info("{$diputuskan} dari {$baris->count()} pendaftaran sudah diputuskan Labkesda.");

        return self::SUCCESS;
    }
}
