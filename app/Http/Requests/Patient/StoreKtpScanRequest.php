<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class StoreKtpScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Staf yang boleh mengajukan perubahan data pasien / menyusun pengiriman sampel.
        return (bool) $this->user()?->hasAnyRole(['super_admin', 'admin_puskesmas', 'pj_prolanis']);
    }

    /**
     * Batas 2 MB mengikuti upload_max_filesize PHP di server production. Frontend sudah
     * memperkecil foto (sisi terpanjang 1600 px, JPEG) sebelum diunggah, jadi foto KTP normal
     * jauh di bawah batas ini.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Foto KTP belum dipilih.',
            'image.mimes' => 'Foto KTP harus berformat JPG atau PNG.',
            'image.max' => 'Ukuran foto KTP maksimal 2 MB.',
            'image.uploaded' => 'Foto KTP gagal diunggah. Ukuran maksimal 2 MB.',
        ];
    }
}
