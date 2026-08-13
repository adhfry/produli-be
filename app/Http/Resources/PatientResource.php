<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shaping response pasien -- SENGAJA tidak menyertakan nik_hash (kunci pencocokan internal,
 * tidak berguna & tidak perlu diekspos ke frontend) meski model aslinya menyimpan itu.
 *
 * `nik` DITAMPILKAN (permintaan Kepala Dinas, dashboard/pasien + detail pasien) tapi SELALU
 * lewat App\Support\NikDisplay::resolve() -- NIK asli hanya keluar dari sini kalau diawali kode
 * wilayah Sumenep (3529), selain itu "Tidak Diketahui". Sama persis aturan yang dipakai laporan
 * PDF, supaya tidak pernah ada jalur yang membocorkan NIK luar-wilayah/tidak valid mentah-mentah.
 */
class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_patient_id' => $this->external_patient_id,
            'no_reg' => $this->no_reg,
            'nik' => \App\Support\NikDisplay::resolve($this->nik),
            'nama' => $this->nama,
            'gender' => $this->gender,
            'tgl_lahir' => $this->tgl_lahir?->toDateString(),
            'phone' => $this->phone,
            'alamat' => $this->alamat,
            'rt_rw' => $this->rt_rw,
            'kel_desa_raw' => $this->kel_desa_raw,
            'kecamatan_raw' => $this->kecamatan_raw,
            'is_prolanis' => $this->is_prolanis,
            'jenis_prolanis' => $this->jenis_prolanis,
            'is_perokok' => $this->is_perokok,
            'jenis_perokok' => $this->jenis_perokok,
            'wilayah_status' => $this->wilayah_status,
            'puskesmas_resolution_method' => $this->puskesmas_resolution_method,
            // Teks asli `pengirim` (surat_hasil_labs SiLAKES) yang dipakai WilayahResolver
            // (revisi Bu Kadis, Fase 5) -- dipakai frontend menampilkan "Rujukan: dr. X" kalau
            // method='pengirim_individual', atau teks mentah untuk audit kasus unresolvable.
            'pengirim_raw' => $this->pengirim_raw,
            'desa' => $this->whenLoaded('desa', fn () => [
                'id' => $this->desa->id,
                'nama' => $this->desa->nama,
            ]),
            // Kecamatan hasil match WilayahResolver -- BISA terisi walau 'desa' di atas null
            // (kecamatan dikenali dari kecamatan_raw, desa belum/tidak match) -- BUKAN
            // diturunkan dari desa.kecamatan, field ini independen persis supaya kasus itu
            // tidak hilang (lihat App\Models\PatientsCache::kecamatan()).
            'kecamatan' => $this->whenLoaded('kecamatan', fn () => [
                'id' => $this->kecamatan->id,
                'nama' => $this->kecamatan->nama,
            ]),
            'puskesmas' => $this->whenLoaded('puskesmas', fn () => [
                'id' => $this->puskesmas->id,
                'nama' => $this->puskesmas->nama,
            ]),
            'geo_status' => $this->geo_status,
            'geo_source' => $this->geo_source,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'risk_level' => $this->whenLoaded(
                'latestRiskClassification',
                fn () => $this->latestRiskClassification?->level,
            ),
            'risk_computed_at' => $this->whenLoaded(
                'latestRiskClassification',
                fn () => $this->latestRiskClassification?->computed_at?->toIso8601String(),
            ),
            // Smart Early Detection (revisi Bu Kadis) -- cuma relevan saat risk_level='sedang',
            // lihat RiskClassificationService::evaluateEarlyDetection().
            'early_detection_flag' => $this->whenLoaded(
                'latestRiskClassification',
                fn () => (bool) $this->latestRiskClassification?->early_detection_flag,
            ),
            'early_detection_reason' => $this->whenLoaded(
                'latestRiskClassification',
                fn () => $this->latestRiskClassification?->early_detection_reason,
            ),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
