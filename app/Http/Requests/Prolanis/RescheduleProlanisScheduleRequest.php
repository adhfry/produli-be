<?php

namespace App\Http\Requests\Prolanis;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleProlanisScheduleRequest extends FormRequest
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
