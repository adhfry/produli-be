<?php

namespace App\Http\Requests\PengirimanSampel;

use Illuminate\Foundation\Http\FormRequest;

class AddPatientToPengirimanSampelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (PengirimanSampelPolicy::update) di controller.
        return true;
    }

    /**
     * Dua jalur: pasien yang SUDAH ada (cukup external_patient_id) ATAU pasien BARU (identitas
     * lengkap wajib). 'required_without' saling silang supaya salah satu jalur tetap tervalidasi
     * penuh, bukan campur separuh-separuh.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_patient_id' => ['nullable', 'integer'],
            'name' => ['required_without:external_patient_id', 'string', 'max:255'],
            'nik' => ['required_without:external_patient_id', 'digits:16'],
            'gender' => ['required_without:external_patient_id', 'in:L,P'],
            'tempat_lahir' => ['required_without:external_patient_id', 'string', 'max:255'],
            'tgl_lahir' => ['required_without:external_patient_id', 'date_format:Y-m-d', 'before:today'],
            'phone' => ['required_without:external_patient_id', 'string', 'max:15'],
            'alamat' => ['required_without:external_patient_id', 'string'],
            'rt_rw' => ['nullable', 'string', 'max:50'],
            'kel_desa' => ['nullable', 'string', 'max:255'],
            'kecamatan' => ['nullable', 'string', 'max:255'],
            'no_bpjs' => ['nullable', 'string', 'max:50'],
            'jenis_prolanis' => ['nullable', 'in:DM,HT,DM_HT'],
        ];
    }
}
