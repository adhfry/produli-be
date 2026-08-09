<?php

namespace App\Http\Requests\Visit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkCreateVisitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (VisitAssignmentPolicy::createBulk) di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kader_id' => ['required', 'integer', 'exists:kader,id'],
            // Kunjungan berombongan (docs/planning/02 §16) -- kader TAMBAHAN yang ikut
            // mendampingi kader_id (primer) untuk SELURUH pasien dalam batch ini. Beda dari
            // patient_ids: exists:kader,id di sini AMAN dipakai (bukan bagian loop
            // partial-success per pasien) -- companion adalah precondition batch, sama seperti
            // kader_id sendiri, jadi wajar gagal validasi di request kalau ID-nya tidak ada.
            'companion_kader_ids' => ['nullable', 'array'],
            'companion_kader_ids.*' => [
                'integer',
                'exists:kader,id',
                'distinct',
                // Kader primer tidak boleh menjadi pendampingnya sendiri.
                Rule::notIn(array_filter([$this->input('kader_id')])),
            ],
            // Sengaja TANPA rule exists:patients_cache,id di sini -- pasien yang tidak
            // ditemukan dilaporkan sebagai kegagalan per-item (docs/planning/02 §12, partial
            // success), bukan menggagalkan seluruh request cuma karena satu ID salah/basi.
            'patient_ids' => ['required', 'array', 'min:1'],
            'patient_ids.*' => ['integer'],
            'scheduled_date' => ['required', 'date'],
            'priority' => ['required', 'string', 'in:ringan,sedang,berat'],
        ];
    }
}
