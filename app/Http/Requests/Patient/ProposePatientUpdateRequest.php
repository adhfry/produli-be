<?php

namespace App\Http\Requests\Patient;

use App\Models\PatientsCache;
use App\Support\NikKoreksi;
use App\Support\NikParser;
use App\Support\PatientFormRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

class ProposePatientUpdateRequest extends FormRequest
{
    /** Field data pasien yang bisa diusulkan (urutan = urutan tampil di SiLAKES). */
    public const FIELDS = [
        'nik', 'name', 'tempat_lahir', 'tgl_lahir', 'gender',
        'alamat', 'rt_rw', 'kecamatan', 'kel_desa', 'phone', 'email',
        'pekerjaan', 'status_perkawinan', 'golongan_darah', 'agama',
        'is_bpjs', 'no_bpjs', 'fktp', 'jenis_prolanis', 'jenis_perokok',
    ];

    public function authorize(): bool
    {
        // Gerbang sebenarnya (PatientsCachePolicy::update -- scoped ke puskesmas yang sama)
        // dicek di controller -- request ini cuma validasi bentuk data.
        return true;
    }

    /**
     * Revisi 2026-11-16: form lengkap seperti registrasi SiLAKES (identitas, NIK untuk semua
     * pasien, scan KTP). Aturan nilai disamakan dengan validasi SiLAKES
     * (IntegrationController::submitFieldUpdate) supaya usulan tidak ditolak di sana setelah
     * staf menekan Ajukan. Semua opsional -- staf hanya mengirim yang berubah.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...PatientFormRules::identitas(required: false),
            'nik' => NikKoreksi::RULES,
            'is_bpjs' => ['nullable', 'boolean'],
            'jenis_perokok' => ['nullable', 'in:AKTIF,PASIF'],
            'ktp_scan_id' => ['nullable', 'integer'],
            'konfirmasi_beda_nik' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return PatientFormRules::messages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(PatientFormRules::normalize($this->all()));
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $patient = $this->route('patient');
                $patient = $patient instanceof PatientsCache ? $patient : null;

                if ($this->filled('nik') && ! $validator->errors()->has('nik')) {
                    $galat = NikKoreksi::galat($patient, (string) $this->input('nik'));
                    if ($galat) {
                        $validator->errors()->add('nik', $galat);
                    }
                }

                if ($validator->errors()->isNotEmpty() || $this->boolean('konfirmasi_beda_nik')) {
                    return;
                }

                // Cek silang dengan nilai EFEKTIF (usulan, atau data sekarang bila tidak diubah),
                // aturan sama dengan SiLAKES. Bisa dilewati dengan konfirmasi eksplisit karena KTP
                // lama kadang memang tidak konsisten.
                $nik = $this->input('nik') ?: $patient?->nik;
                $tgl = $this->input('tgl_lahir') ?: $patient?->tgl_lahir?->format('Y-m-d');
                $gender = $this->input('gender') ?: $patient?->gender;
                $yangDiubah = array_filter([$this->filled('nik'), $this->filled('tgl_lahir'), $this->filled('gender')]);

                if ($yangDiubah !== []) {
                    foreach (NikParser::mismatches($nik, $tgl, $gender) as $field => $pesan) {
                        $validator->errors()->add($field, $pesan.' Centang konfirmasi bila data KTP memang begitu.');
                    }
                }
            },
        ];
    }

    /**
     * Cuma field yang benar-benar diisi klien -- dikirim apa adanya ke SiLAKES lewat
     * SyncPatientFieldUpdateToSilakesJob.
     *
     * @return array<string, mixed>
     */
    public function proposedFields(): array
    {
        $fields = Arr::only($this->validated(), self::FIELDS);

        if (array_key_exists('is_bpjs', $fields) && $fields['is_bpjs'] !== null) {
            $fields['is_bpjs'] = $this->boolean('is_bpjs');
        }

        return array_filter($fields, fn ($value) => $value !== null && $value !== '');
    }

    public function ktpScanId(): ?int
    {
        return $this->filled('ktp_scan_id') ? (int) $this->input('ktp_scan_id') : null;
    }
}
