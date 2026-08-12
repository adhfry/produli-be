<?php

namespace App\Http\Requests\CareAssignment;

use Illuminate\Foundation\Http\FormRequest;

class CreateAdhocVisitRequest extends FormRequest
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
            'scheduled_date' => ['required', 'date'],
        ];
    }
}
