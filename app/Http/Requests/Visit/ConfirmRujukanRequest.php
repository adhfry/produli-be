<?php

namespace App\Http\Requests\Visit;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmRujukanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya lewat Policy (VisitReportPolicy::confirmRujukan) di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:dikonfirmasi,dibatalkan'],
        ];
    }
}
