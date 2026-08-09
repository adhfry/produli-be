<?php

namespace App\Http\Requests\Puskesmas;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePuskesmasRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (PuskesmasPolicy::update) di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `nama`/`kode_internal` SENGAJA tidak ada rule di sini (docs/planning/02 §15:
            // identitas resmi, dikunci) -- kalaupun klien kirim, tidak pernah masuk validated().
            'alamat' => ['nullable', 'string'],
            'no_telp' => ['nullable', 'string', 'max:20'],
            'no_wa' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'deskripsi' => ['nullable', 'string'],
        ];
    }
}
