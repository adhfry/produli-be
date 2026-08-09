<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResendActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya (admin & scope puskesmas) dicek di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
