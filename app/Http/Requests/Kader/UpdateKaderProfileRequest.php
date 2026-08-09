<?php

namespace App\Http\Requests\Kader;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKaderProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (KaderPolicy::updateOwnProfile) di controller.
        return true;
    }

    /**
     * Cuma no_wa/alamat/gender/tgl_lahir -- no_hp SENGAJA tidak di sini (docs/planning/02 §7:
     * itu wajib diisi PJ saat registrasi, bukan field self-service).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'no_wa' => ['nullable', 'string', 'max:20'],
            'alamat' => ['nullable', 'string'],
            'gender' => ['nullable', 'string', 'in:L,P'],
            'tgl_lahir' => ['nullable', 'date', 'before:today'],
        ];
    }
}
