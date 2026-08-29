<?php

namespace App\Http\Requests\PengirimanSampel;

use Illuminate\Foundation\Http\FormRequest;

class ReorderPengirimanSampelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (PengirimanSampelPolicy::update) di controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pasien_ids' => ['required', 'array', 'min:1'],
            'pasien_ids.*' => ['integer'],
        ];
    }
}
