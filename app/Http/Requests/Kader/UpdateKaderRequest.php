<?php

namespace App\Http\Requests\Kader;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (KaderPolicy::update) di controller.
        return true;
    }

    /**
     * 'sometimes' -- PATCH parsial, sama pola dengan UpdateProfileRequest. name/email menyentuh
     * users.name/users.email (bukan tabel kader), lihat KaderService::update().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kader = $this->route('kader');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($kader?->user_id)],
            'no_hp' => ['sometimes', 'string', 'max:20'],
            'no_wa' => ['sometimes', 'nullable', 'string', 'max:20'],
            'alamat' => ['sometimes', 'nullable', 'string'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:L,P'],
            'tgl_lahir' => ['sometimes', 'nullable', 'date', 'before:today'],
            // Cuma relevan utk admin_puskesmas/super_admin (pj_prolanis dipaksa ke ID sendiri,
            // sama pola dengan resolvePjId() saat registrasi).
            'pj_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
