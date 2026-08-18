<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class OverridePuskesmasRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya (super_admin BEBAS, admin_puskesmas/pj_prolanis HANYA boleh klaim
        // ke puskesmas MEREKA SENDIRI) dicek di controller -- lihat PatientController::
        // overridePuskesmas(). Tidak bisa lewat Policy biasa karena scoping-nya bergantung pada
        // puskesmas TUJUAN (request payload), bukan puskesmas pasien SAAT INI seperti Policy lain.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'puskesmas_id' => ['required', 'integer', 'exists:puskesmas,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
