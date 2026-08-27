<?php

namespace App\Http\Requests\CareAssignment;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleCareAssignmentRequest extends FormRequest
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
            'next_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
