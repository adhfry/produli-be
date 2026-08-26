<?php

namespace App\Http\Requests\Visit;

use Illuminate\Foundation\Http\FormRequest;

class MultiDateVisitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (VisitAssignmentPolicy::create, dipakai ulang --
        // otorisasi patient+kader sama persis assign satu-tanggal) di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients_cache,id'],
            'kader_id' => ['required', 'integer', 'exists:kader,id'],
            // max:31 -- sanity cap sebulan penuh, bukan batasan bisnis (lihat
            // VisitAssignmentService::assignMultipleDates() utk aturan sebenarnya).
            'scheduled_dates' => ['required', 'array', 'min:1', 'max:31'],
            'scheduled_dates.*' => ['date', 'distinct', 'after_or_equal:today'],
            'priority' => ['required', 'string', 'in:ringan,sedang,berat'],
        ];
    }

    /**
     * Terurut ascending -- VisitAssignmentService::assignMultipleDates() docblock mensyaratkan
     * ini (last_triggered_at cadence dimajukan ke tanggal TERAKHIR dari batch, harus benar
     * urutannya).
     *
     * @return string[]
     */
    public function sortedScheduledDates(): array
    {
        $dates = $this->validated('scheduled_dates');
        sort($dates);

        return $dates;
    }
}
