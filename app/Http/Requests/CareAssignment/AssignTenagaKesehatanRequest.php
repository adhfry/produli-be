<?php

namespace App\Http\Requests\CareAssignment;

use Illuminate\Foundation\Http\FormRequest;

class AssignTenagaKesehatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients_cache,id'],
            'tenaga_kesehatan_id' => ['required', 'integer', 'exists:tenaga_kesehatan,id'],
            'scheduled_date' => ['required', 'date'],
        ];
    }
}
