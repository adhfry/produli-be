<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CompleteOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Field profil kader SEMUA opsional (docs/planning/02 §14) -- sama persis dengan
     * UpdateKaderProfileRequest, dikirim di request yang sama supaya kader tidak perlu panggil
     * PATCH /kader/profile terpisah. Diabaikan kalau user yang login bukan kader (lihat
     * AuthController::completeOnboarding()).
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
