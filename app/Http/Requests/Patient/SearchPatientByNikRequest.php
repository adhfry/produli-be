<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class SearchPatientByNikRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang scope data (PatientsCachePolicy::viewAny) dicek di controller -- request ini
        // cuma validasi bentuk data.
        return true;
    }

    /**
     * POST (bukan GET) SENGAJA -- password re-autentikasi tidak boleh nyangkut di query string
     * (log akses server, riwayat browser). 'password' dicek server-side terhadap password user
     * yang SEDANG LOGIN sendiri (step-up auth, bukan password pasien -- pasien tidak punya akun).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nik' => ['required', 'digits:16'],
            'password' => ['required', 'string'],
        ];
    }
}
