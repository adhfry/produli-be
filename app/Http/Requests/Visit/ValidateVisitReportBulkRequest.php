<?php

namespace App\Http\Requests\Visit;

use Illuminate\Foundation\Http\FormRequest;

class ValidateVisitReportBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya (super_admin only, docs/planning/02 §11) dicek di controller lewat
        // VisitReportPolicy::validateReport() -- sama persis dengan validasi satuan.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_ids' => ['required', 'array', 'min:1', 'max:100'],
            'report_ids.*' => ['integer', 'distinct', 'exists:visit_reports,id'],
            'is_valid' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
        ];
    }
}
