<?php

namespace App\Http\Requests\PengirimanSampel;

use App\Models\PatientsCache;
use App\Support\NikHasher;
use App\Support\NikParser;
use App\Support\PatientFormRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AddPatientToPengirimanSampelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (PengirimanSampelPolicy::update) di controller.
        return true;
    }

    /**
     * Dua jalur: pasien yang SUDAH ada (cukup external_patient_id) ATAU pasien BARU. Pasien baru
     * (revisi 2026-11-16) diisi lengkap seperti registrasi SiLAKES, boleh dibantu scan KTP:
     * identitas inti wajib, sisanya opsional tapi divalidasi dengan aturan SiLAKES.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->filled('external_patient_id')) {
            return ['external_patient_id' => ['required', 'integer']];
        }

        return [
            ...PatientFormRules::identitas(required: true),
            'nik' => ['required', 'digits:16'],
            'ktp_scan_id' => ['nullable', 'integer'],
            'konfirmasi_beda_nik' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...PatientFormRules::messages(),
            'nik.required' => 'NIK wajib diisi untuk pasien baru.',
            'nik.digits' => 'NIK harus 16 digit angka sesuai KTP.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(PatientFormRules::normalize($this->all()));
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('external_patient_id') || $validator->errors()->has('nik')) {
                    return;
                }

                $nik = (string) $this->input('nik');
                $parsed = NikParser::parse($nik);
                if (! $parsed['valid']) {
                    $validator->errors()->add('nik', $parsed['alasan']);

                    return;
                }

                // Pasien yang sudah ada di PRODULI tidak boleh didaftarkan ulang sebagai pasien
                // baru -- SiLAKES akan menolak karena NIK unik, dan riwayat lab pasien terpecah.
                $sudahAda = PatientsCache::query()
                    ->where(fn ($q) => $q->where('nik', $nik)->orWhere('nik_hash', NikHasher::hash($nik)))
                    ->first(['id', 'nama']);
                if ($sudahAda) {
                    $validator->errors()->add('nik', "NIK ini sudah terdaftar atas nama {$sudahAda->nama}. Tambahkan lewat pencarian pasien terdaftar, bukan sebagai pasien baru.");

                    return;
                }

                if ($this->boolean('konfirmasi_beda_nik')) {
                    return;
                }

                foreach (NikParser::mismatches($nik, $this->input('tgl_lahir'), $this->input('gender')) as $field => $pesan) {
                    $validator->errors()->add($field, $pesan.' Centang konfirmasi bila data KTP memang begitu.');
                }
            },
        ];
    }
}
