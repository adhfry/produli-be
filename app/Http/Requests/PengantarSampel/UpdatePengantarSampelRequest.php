<?php

namespace App\Http\Requests\PengantarSampel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePengantarSampelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (PengantarSampelPolicy::update) di controller.
        return true;
    }

    /**
     * Mirror persis UpdateTenagaKesehatanRequest (lihat docblock di sana) -- 'sometimes', PATCH parsial.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pengantarSampel = $this->route('pengantarSampel');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($pengantarSampel?->user_id)],
            'no_hp' => ['sometimes', 'string', 'max:20'],
            'no_wa' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
