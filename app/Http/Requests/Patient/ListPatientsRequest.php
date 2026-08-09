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
            'risk_level' => ['nullable', 'string', 'in:ringan,sedang,berat'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
