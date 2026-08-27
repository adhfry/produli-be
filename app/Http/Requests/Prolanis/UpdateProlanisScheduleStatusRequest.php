<?php

namespace App\Http\Requests\Prolanis;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProlanisScheduleStatusRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:terjadwal,selesai,dibatalkan'],
        ];
    }
}
