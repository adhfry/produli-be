<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class ListPatientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang scope data sebenarnya dicek lewat Policy (PatientsCachePolicy::viewAny) di
        // controller -- request ini cuma validasi bentuk query string.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wilayah_status' => ['nullable', 'string', 'in:resolved,unresolved,unknown,out_of_scope'],
            'risk_level' => ['nullable', 'string', 'in:tidak_berisiko,ringan,sedang,berat'],
            // Kecamatan_id (BUKAN nama teks bebas) -- filter lewat kolom patients_cache.kecamatan_id
            // hasil match WilayahResolver, supaya akurat terlepas dari variasi ejaan/kapitalisasi
            // kecamatan_raw dari SiLAKES (lihat App\Models\PatientsCache::kecamatan()).
            'kecamatan_id' => ['nullable', 'integer', 'exists:kecamatan,id'],
            // HANYA berlaku utk full-access (super_admin) -- lihat PatientController::index().
            // admin_puskesmas/pj_prolanis SUDAH terkunci ke puskesmas sendiri lewat
            // PatientQueryService::scopedQuery(), input ini diabaikan utk mereka (bukan celah
            // otorisasi -- pola sama seperti DashboardService::summaryFor() $puskesmasId).
            'puskesmas_id' => ['nullable', 'integer', 'exists:puskesmas,id'],
            // Pencarian nama/no. registrasi -- SERVER-SIDE (bukan cuma di window yang ke-fetch),
            // supaya kombinasi dengan filter lain (wilayah_status/risk_level/kecamatan_id) tetap
            // akurat, bukan cuma nyaring dari halaman yang kebetulan sudah dimuat.
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            // "Deteksi Dini Aktif" (revisi Bu Kadis) -- pasien Sedang berpotensi memburuk ke Berat.
            'early_detection_only' => ['nullable', 'boolean'],
            // Header tabel dashboard/pasien yang bisa diklik utk sort (revisi Bu Kadis).
            'sort_by' => ['nullable', 'string', 'in:nama,risk_level'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
