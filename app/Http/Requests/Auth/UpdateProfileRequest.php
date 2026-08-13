<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Semua role login boleh update preferensinya sendiri -- endpoint ini SELALU
        // beroperasi di profil milik user yang login (docs/planning/02 §17).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 'sometimes' (bukan 'required') -- PATCH parsial. email/roles/puskesmas_id SENGAJA
            // tidak ada di sini -- identitas resmi & penugasan, bukan data diri yang bisa
            // diubah sendiri oleh user (email juga dipakai login, puskesmas_id ditentukan
            // penugasan dari admin/PJ, bukan preferensi pribadi).
            'email_notifications_enabled' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:150'],
            'no_hp' => ['sometimes', 'nullable', 'string', 'max:20'],
            // Field yang sama dengan CompleteOnboardingRequest (dulu HANYA bisa diisi sekali
            // saat onboarding, tidak ada jalan untuk diedit lagi setelahnya -- lihat
            // ProfileService::updatePreferences()).
            'no_wa' => ['sometimes', 'nullable', 'string', 'max:20'],
            'alamat' => ['sometimes', 'nullable', 'string'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:L,P'],
            'tgl_lahir' => ['sometimes', 'nullable', 'date', 'before:today'],
        ];
    }
}
