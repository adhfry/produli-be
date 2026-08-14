<?php

namespace App\Http\Requests\TenagaKesehatan;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenagaKesehatanProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (TenagaKesehatanPolicy::updateOwnProfile) di
        // controller -- mirror persis UpdateKaderProfileRequest.
        return true;
    }

    /**
     * Cuma no_wa/alamat/gender/tgl_lahir -- no_hp SENGAJA tidak di sini (wajib diisi PJ/admin
     * saat registrasi, bukan field self-service), sama seperti kader.
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
