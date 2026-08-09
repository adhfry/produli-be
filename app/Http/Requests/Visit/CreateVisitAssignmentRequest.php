<?php

namespace App\Http\Requests\Visit;

use Illuminate\Foundation\Http\FormRequest;

class CreateVisitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (VisitAssignmentPolicy::create) di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients_cache,id'],
            'kader_id' => ['required', 'integer', 'exists:kader,id'],
            'scheduled_date' => ['required', 'date'],
            'priority' => ['required', 'string', 'in:ringan,sedang,berat'],
        ];
    }
}
