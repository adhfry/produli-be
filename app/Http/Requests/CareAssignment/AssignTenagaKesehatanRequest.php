<?php

namespace App\Http\Requests\CareAssignment;

use Illuminate\Foundation\Http\FormRequest;

class AssignTenagaKesehatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients_cache,id'],
            'tenaga_kesehatan_id' => ['required', 'integer', 'exists:tenaga_kesehatan,id'],
            'scheduled_date' => ['required', 'date'],
            // Kunjungan hari-1 bersama kader (revisi Bu Kadis PMO) -- opsional: kalau diisi,
            // kader ditandai sebagai pendamping kunjungan pertama nakes ini DAN rencana
            // kunjungan mingguan kader langsung diaktifkan (lihat CareAssignmentService::
            // assignTenagaKesehatan()).
            'kader_id' => ['nullable', 'integer', 'exists:kader,id'],
        ];
    }
}
