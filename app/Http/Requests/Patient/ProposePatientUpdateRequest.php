<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class ProposePatientUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya (PatientsCachePolicy::update -- scoped ke puskesmas yang sama)
        // dicek di controller -- request ini cuma validasi bentuk data.
        return true;
    }

    /**
     * Field & rule SAMA PERSIS dengan SubmitVisitReportRequest::patientFieldUpdates() (kader,
     * lewat visit-reports) -- ini jalur PARALEL untuk staf (admin_puskesmas/pj_prolanis) yang
     * TIDAK terikat laporan kunjungan. SEMUA opsional -- staf isi apa yang dia tahu akurat,
     * bukan form lengkap wajib.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alamat' => ['nullable', 'string', 'max:500'],
            'kel_desa' => ['nullable', 'string', 'max:100'],
            'kecamatan' => ['nullable', 'string', 'max:100'],
            'rt_rw' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'pekerjaan' => ['nullable', 'string', 'max:100'],
            'status_perkawinan' => ['nullable', 'string', 'max:50'],
            'golongan_darah' => ['nullable', 'string', 'max:5'],
            'agama' => ['nullable', 'string', 'max:50'],
            'is_bpjs' => ['nullable', 'boolean'],
            'no_bpjs' => ['nullable', 'string', 'max:50'],
            'jenis_prolanis' => ['nullable', 'string', 'max:50'],
            'jenis_perokok' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Cuma field yang benar-benar diisi klien (bukan array penuh null) -- dikirim apa adanya ke
     * SiLAKES lewat SyncPatientFieldUpdateToSilakesJob, sama pola dengan
     * SubmitVisitReportRequest::patientFieldUpdates().
     *
     * @return array<string, mixed>
     */
    public function proposedFields(): array
    {
        $fields = Arr::only($this->validated(), [
            'alamat', 'kel_desa', 'kecamatan', 'rt_rw', 'phone', 'pekerjaan',
            'status_perkawinan', 'golongan_darah', 'agama', 'is_bpjs', 'no_bpjs',
            'jenis_prolanis', 'jenis_perokok',
        ]);

        if (array_key_exists('is_bpjs', $fields)) {
            $fields['is_bpjs'] = $this->boolean('is_bpjs');
        }

        return array_filter($fields, fn ($value) => $value !== null);
    }
}
