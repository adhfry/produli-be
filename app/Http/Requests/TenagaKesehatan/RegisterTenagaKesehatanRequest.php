<?php

namespace App\Http\Requests\TenagaKesehatan;

use Illuminate\Foundation\Http\FormRequest;

class RegisterTenagaKesehatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (TenagaKesehatanPolicy::create) di controller.
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
            'alamat' => ['nullable', 'string'],
            'gender' => ['nullable', 'string', 'in:L,P'],
            'tgl_lahir' => ['nullable', 'date', 'before:today'],
            'puskesmas_id' => ['nullable', 'integer', 'exists:puskesmas,id'],
            'pj_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
