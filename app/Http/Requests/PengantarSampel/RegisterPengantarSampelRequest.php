<?php

namespace App\Http\Requests\PengantarSampel;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPengantarSampelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (PengantarSampelPolicy::create) di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'no_hp' => ['required', 'string', 'max:20'],
            'no_wa' => ['nullable', 'string', 'max:20'],
            'puskesmas_id' => ['nullable', 'integer', 'exists:puskesmas,id'],
        ];
    }
}
