<?php

namespace App\Http\Requests\PengirimanSampel;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmArrivalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (PengirimanSampelPolicy::isAssignedCourier) di
        // controller -- request ini cuma validasi bentuk data.
        return true;
    }

    /**
     * Mirror persis SubmitVisitReportRequest (foto+GPS) -- gps_captured_at WAJIB (bukan default
     * now() di server), sama alasan anti-fraud: timestamp GPS device adalah bukti waktu
     * pengambilan sesungguhnya, bukan waktu request sampai ke server.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'gps_captured_at' => ['required', 'date'],
        ];
    }
}
