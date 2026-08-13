<?php

namespace App\Http\Requests\TenagaKesehatan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenagaKesehatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (TenagaKesehatanPolicy::update) di controller.
        return true;
    }

    /**
     * Mirror persis UpdateKaderRequest (lihat docblock di sana) -- 'sometimes', PATCH parsial.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenagaKesehatan = $this->route('tenagaKesehatan');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($tenagaKesehatan?->user_id)],
            'no_hp' => ['sometimes', 'string', 'max:20'],
            'no_wa' => ['sometimes', 'nullable', 'string', 'max:20'],
            'alamat' => ['sometimes', 'nullable', 'string'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:L,P'],
            'tgl_lahir' => ['sometimes', 'nullable', 'date', 'before:today'],
            'pj_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
