<?php

namespace App\Jobs;

use App\Exceptions\SilakesApiException;
use App\Models\PengirimanSampelPasien;
use App\Services\PengirimanSampel\PasienBaruPayload;
use App\Services\Silakes\SilakesApiClient;
use App\Services\Silakes\SilakesEventService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mendaftarkan pasien baru ke SiLAKES SAAT staf puskesmas mengisinya di antrian pengiriman
 * sampel (revisi 2026-11-17), bukan menunggu sampel tiba. SiLAKES menyimpannya sebagai usulan
 * pendaftaran: ditinjau staf Labkesda atau disetujui otomatis (switch auto approve), lalu
 * hasilnya didorong balik ke PRODULI (SilakesEventService).
 *
 * Bila gagal terkirim, baris tetap ada di antrian sebagai pasien baru dan ikut terkirim bersama
 * batch saat sampel tiba (alur lama), jadi tidak ada pasien yang hilang.
 */
class RegisterPasienBaruToSilakesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $barisId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(SilakesApiClient $client, SilakesEventService $events): void
    {
        $baris = PengirimanSampelPasien::with(['pengirimanSampel.puskesmas', 'diinputOleh'])->find($this->barisId);
        if (! $baris || ! $baris->isPasienBaru() || $baris->registration_proposal_ref !== null
            || in_array($baris->registrasi_status, ['terdaftar', 'ditolak'], true)) {
            return;
        }

        $batch = $baris->pengirimanSampel;
        $payload = [
            ...PasienBaruPayload::untuk($baris),
            'puskesmas_asal' => $batch->puskesmas?->nama ?? '-',
            'produli_puskesmas_id' => $batch->puskesmas_id,
            'produli_pengaju_nama' => $baris->diinputOleh?->name,
            'produli_pengaju_user_id' => $baris->diinput_oleh_user_id,
        ];

        try {
            $result = $client->postPatientRegistration($payload);
        } catch (SilakesApiException $e) {
            if ($e->isClientError()) {
                $this->tandaiGagal($baris, $e->getMessage());
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $data = $result['data'] ?? [];
        if (($data['status'] ?? null) === 'sudah_terdaftar') {
            $events->terapkanPendaftaran($data, $baris);

            return;
        }

        // Bersyarat: dengan auto approve, event "disetujui" dari SiLAKES bisa tiba LEBIH DULU dari
        // balasan ini. Status yang sudah diputuskan (terdaftar/ditolak) tidak boleh ditimpa.
        PengirimanSampelPasien::whereKey($baris->id)
            ->where(fn ($q) => $q->whereNull('registrasi_status')->orWhereIn('registrasi_status', ['mengirim', 'gagal_kirim']))
            ->update([
                'registration_proposal_ref' => $data['registration_proposal_id'] ?? null,
                'registrasi_status' => 'menunggu_verifikasi',
                'registrasi_diperbarui_at' => now(),
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        $baris = PengirimanSampelPasien::find($this->barisId);
        if ($baris && $baris->registrasi_status === 'mengirim') {
            $this->tandaiGagal($baris, $exception?->getMessage());
        }

        Log::warning('Gagal mendaftarkan pasien baru ke SiLAKES', [
            'pengiriman_sampel_pasien_id' => $this->barisId,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function tandaiGagal(PengirimanSampelPasien $baris, ?string $pesan): void
    {
        $baris->update([
            'registrasi_status' => 'gagal_kirim',
            'registrasi_catatan' => 'Belum terkirim ke Labkesda; data ikut terkirim saat sampel tiba.'.($pesan ? ' ('.mb_substr($pesan, 0, 200).')' : ''),
            'registrasi_diperbarui_at' => now(),
        ]);
    }
}
