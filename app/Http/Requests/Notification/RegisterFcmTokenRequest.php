<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class RegisterFcmTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Semua user login boleh daftarkan token FCM miliknya sendiri -- tidak ada Policy
        // terpisah, scoping ke user sendiri sudah cukup lewat $request->user() di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'device_label' => ['nullable', 'string', 'max:100'],
        ];
    }
}
