<?php

namespace App\Http\Requests\Visit;

use Illuminate\Foundation\Http\FormRequest;

class InputTindakanLanjutanRujukanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya lewat Policy (VisitReportPolicy::confirmRujukan, sama dgn
        // konfirmasi/batalkan -- aktor & scope puskesmas identik) di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tindakan_puskesmas' => ['required', 'array', 'min:1'],
            'tindakan_puskesmas.*' => ['string', 'in:rawat_inap,edukasi,obat_tambahan,lainnya'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
