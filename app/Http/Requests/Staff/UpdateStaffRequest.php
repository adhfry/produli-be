<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya (super_admin/admin_puskesmas, lihat StaffService::ensureCanManage())
        // dicek di controller + StaffService, sama pola dengan RegisterStaffRequest.
        return true;
    }

    /**
     * 'sometimes' -- PATCH parsial. role/puskesmas_id SENGAJA tidak bisa diubah lewat sini
     * (pindah role/puskesmas staf terlalu berisiko diam-diam lewat endpoint umum -- kalau perlu,
     * hapus staf ini lalu daftarkan ulang dengan role/puskesmas yang benar).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staff = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($staff?->id)],
            'no_hp' => ['sometimes', 'string', 'max:20'],
        ];
    }
}
