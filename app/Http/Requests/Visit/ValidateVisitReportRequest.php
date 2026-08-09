<?php

namespace App\Http\Requests\Visit;

use Illuminate\Foundation\Http\FormRequest;

class ValidateVisitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya (super_admin only, docs/planning/02 §11) dicek di controller lewat
        // VisitReportPolicy::validateReport().
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_valid' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
        ];
    }
}
