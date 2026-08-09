<?php

namespace App\Http\Requests\Kader;

use Illuminate\Foundation\Http\FormRequest;

class RegisterKaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (KaderPolicy::create) di controller -- request
        // ini cuma validasi bentuk data.
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
            // no_hp WAJIB saat registrasi (dipakai kontak & aktivasi akun) -- beda dari no_wa
            // yang boleh dilengkapi belakangan lewat self-service (docs/planning/02 §7).
            'no_hp' => ['required', 'string', 'max:20'],
            'no_wa' => ['nullable', 'string', 'max:20'],
            'alamat' => ['nullable', 'string'],
            'gender' => ['nullable', 'string', 'in:L,P'],
            'tgl_lahir' => ['nullable', 'date', 'before:today'],
            // Cuma relevan untuk super_admin (admin_puskesmas/pj_prolanis dipaksa ke puskesmas
            // sendiri di KaderService, input ini diabaikan untuk mereka).
            'puskesmas_id' => ['nullable', 'integer', 'exists:puskesmas,id'],
            // Cuma relevan untuk admin_puskesmas/super_admin (pj_prolanis dipaksa ke ID sendiri).
            'pj_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
