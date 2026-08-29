<?php

namespace App\Jobs;

use App\Exceptions\SilakesApiException;
use App\Models\PengirimanSampel;
use App\Services\Silakes\SilakesApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push batch pengiriman sampel ke SiLAKES (modul "Kirim Data Prolanis ke Labkesda Sumenep",
 * Fase D) -- dipanggil SETELAH kurir konfirmasi tiba tersimpan lokal
 * (PengirimanSampelService::confirmArrival()), job TERPISAH dengan retry supaya
 * kegagalan/lambatnya SiLAKES TIDAK PERNAH menggagalkan konfirmasi kurir yang sudah berhasil
 * (mirror persis pola SyncFieldUpdateToSilakesJob).
 *
 * Idempotency guard: `silakes_batch_ref` terisi = sudah pernah sukses, job berikutnya (kalau
 * ter-dispatch dobel) langsung return tanpa POST ulang. Sisi SiLAKES SENDIRI juga idempoten
 * lewat `produli_pengiriman_sampel_id` unique (lihat ProlanisDeliveryIntakeService), jadi POST
 * ganda pun aman kalau guard lokal ini entah bagaimana terlewat.
 */
class SendProlanisDeliveryToSilakesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $pengirimanSampelId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function handle(SilakesApiClient $client): void
    {
        $batch = PengirimanSampel::with(['puskesmas', 'pasien'])->find($this->pengirimanSampelId);

        if (! $batch || $batch->silakes_batch_ref !== null) {
            return;
        }

        $payload = [
            'produli_pengiriman_sampel_id' => $batch->id,
            'produli_puskesmas_id' => $batch->puskesmas_id,
            'puskesmas_asal' => $batch->puskesmas->nama,
            'pengantar_nama' => $batch->pengantarSampel?->user?->name ?? '-',
            'pengantar_no_hp' => $batch->pengantarSampel?->no_hp,
            'pasien' => $batch->pasien->map(fn ($pasien) => $pasien->isPasienBaru() ? [
                'kind' => 'proposal',
                'name' => $pasien->nama_snapshot,
                'nik' => $pasien->data_pasien_baru_nik,
                'gender' => $pasien->data_pasien_baru_gender,
                'tempat_lahir' => $pasien->data_pasien_baru_tempat_lahir,
                'tgl_lahir' => $pasien->data_pasien_baru_tgl_lahir?->format('Y-m-d'),
                'phone' => $pasien->data_pasien_baru_phone,
                'alamat' => $pasien->data_pasien_baru_alamat,
                'rt_rw' => $pasien->data_pasien_baru_rt_rw,
                'kel_desa' => $pasien->data_pasien_baru_kel_desa,
                'kecamatan' => $pasien->data_pasien_baru_kecamatan,
                'no_bpjs' => $pasien->data_pasien_baru_no_bpjs,
                'jenis_prolanis' => $pasien->jenis_prolanis_snapshot,
                'produli_pengiriman_sampel_pasien_id' => $pasien->id,
            ] : [
                'kind' => 'existing',
                'patient_id' => $pasien->external_patient_id,
            ])->all(),
        ];

        try {
            $result = $client->postProlanisDelivery($payload);
        } catch (SilakesApiException $e) {
            if ($e->isClientError()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $silakesDeliveryId = $result['data']['silakes_delivery_id'] ?? null;
        if ($silakesDeliveryId === null) {
            return;
        }

        $batch->update(['silakes_batch_ref' => $silakesDeliveryId]);

        // Simpan registration_proposal_ref per baris pasien baru -- balasan SiLAKES memberi
        // 'items' dalam urutan array YANG SAMA PERSIS dengan $payload['pasien'] di atas (index
        // sejajar, tidak di-reorder di kedua sisi), jadi cukup dipasangkan posisi ke posisi.
        $items = $result['data']['items'] ?? [];
        $pasienList = $batch->pasien->values();
        foreach ($items as $i => $item) {
            $pasien = $pasienList->get($i);
            if ($pasien && $pasien->isPasienBaru() && isset($item['registration_proposal_id'])) {
                $pasien->update(['registration_proposal_ref' => $item['registration_proposal_id']]);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Gagal push pengiriman sampel ke SiLAKES', [
            'pengiriman_sampel_id' => $this->pengirimanSampelId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
