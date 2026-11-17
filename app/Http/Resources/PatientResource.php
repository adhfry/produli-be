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
            'no_bpjs' => $this->no_bpjs,
            'nik' => \App\Support\NikDisplay::resolve($this->nik),
            // Selalu true sejak revisi 2026-11-16 (NIK boleh diajukan untuk semua pasien, lihat
            // App\Support\NikKoreksi); dipertahankan supaya klien lama tetap jalan. NIK mentah
            // tetap tidak pernah keluar dari resource ini.
            'nik_bisa_dikoreksi' => \App\Support\NikKoreksi::bisaDiajukan($this->nik, $this->no_reg),
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
            // Fase 2 blueprint docs/planning/16 (2026-11-17): identitas lengkap & domisili dari SiLAKES.
            'tempat_lahir' => $this->tempat_lahir,
            'golongan_darah' => $this->golongan_darah,
            'agama' => $this->agama,
            'status_perkawinan' => $this->status_perkawinan,
            'pekerjaan' => $this->pekerjaan,
            'is_bpjs' => $this->is_bpjs,
            'fktp' => $this->fktp,
            'email' => $this->email,
            'domisili' => $this->domisili_alamat || $this->domisili_kel_desa || $this->domisili_kecamatan ? [
                'alamat' => $this->domisili_alamat,
                'rt_rw' => $this->domisili_rt_rw,
                'kel_desa' => $this->domisili_kel_desa,
                'kecamatan' => $this->domisili_kecamatan,
                'sumber' => $this->domisili_sumber,
                'updated_at' => $this->domisili_updated_at?->toIso8601String(),
            ] : null,
            'puskesmas_terkunci' => $this->puskesmasTerkunci(),
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
            // Fitur periode bulanan (permintaan user) -- status risiko pasien PERSIS seperti
            // kondisinya di akhir bulan yang diminta lewat ?period=YYYY-MM (lihat
            // PatientController::index()), DI SAMPING risk_level/risk_computed_at di atas (itu
            // tetap status TERKINI, tidak berubah). null kalau ?period= tidak dikirim SAMA
            // SEKALI (whenLoaded -- controller cuma setRelation() saat period diisi), BUKAN
            // berarti pasien tidak punya klasifikasi di bulan itu (itu dibedakan lewat
            // period_risk_level sendiri = null tapi key tetap muncul, lihat whenLoaded()).
            'period_risk_level' => $this->whenLoaded(
                'periodRiskClassification',
                fn () => $this->periodRiskClassification?->level,
            ),
            'period_risk_computed_at' => $this->whenLoaded(
                'periodRiskClassification',
                fn () => $this->periodRiskClassification?->computed_at?->toIso8601String(),
            ),
            // Jadwal kunjungan berulang (permintaan user) -- cuma terisi kalau relasinya
            // di-eager-load (PatientController::show(), BUKAN index() -- daftar pasien tidak
            // perlu jadwal cadence tiap baris, terlalu berat).
            'care_assignments' => $this->whenLoaded(
                'activeCareAssignments',
                fn () => CareAssignmentResource::collection($this->activeCareAssignments),
            ),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            // "Sinkron terakhir" (blueprint §4b): kapan PRODULI terakhir menarik pasien ini dan
            // lewat jalur apa (harian / manual / realtime); silakes_updated_at = waktu ubah di SiLAKES.
            'refreshed_at' => $this->refreshed_at?->toIso8601String(),
            'refreshed_via' => $this->refreshed_via,
            'silakes_updated_at' => $this->silakes_updated_at?->toIso8601String(),
            // Ringkasan kunjungan (permintaan user, kolom "Nx dikunjungi" + tooltip) -- gabungan
            // kader & tenaga_kesehatan, lihat PatientController::index(). whenLoaded() krn
            // visitHistorySummary itu pseudo-relation (setRelation manual, bukan Eloquent
            // relationship sungguhan) -- pola SAMA PERSIS periodRiskClassification di atas.
            'visit_count' => $this->whenLoaded('visitHistorySummary', fn () => $this->visitHistorySummary->count()),
            'visits' => $this->whenLoaded('visitHistorySummary', fn () => $this->visitHistorySummary->map(function ($report) {
                $worker = $report->assignment->kader ?? $report->assignment->tenagaKesehatan;

                return [
                    'tanggal' => $report->created_at?->toIso8601String(),
                    'nama' => $worker?->user?->name,
                    'tipe' => $report->assignment->kader_id !== null ? 'kader' : 'nakes',
                ];
            })->values()),
        ];
    }
}
