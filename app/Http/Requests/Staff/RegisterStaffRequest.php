<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class RegisterStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya (super_admin/admin_puskesmas, lihat docs/planning/02 §11) dicek
        // di controller + StaffService.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'no_hp' => ['required', 'string', 'max:20'],
            // Nullable -- wajib utk registrant super_admin MENDAFTARKAN admin_puskesmas/pj_prolanis
            // (dicek di StaffService::resolvePuskesmasId()), TIDAK relevan sama sekali kalau role
            // yang didaftarkan 'super_admin' sendiri (tidak py puskesmas). admin_puskesmas dipaksa
            // ke puskesmas sendiri, input di sini diabaikan untuk mereka.
            'puskesmas_id' => ['nullable', 'integer', 'exists:puskesmas,id'],
            // 'super_admin' HANYA bisa didaftarkan oleh super_admin (StaffService::ensureRoleAllowed) --
            // admin_puskesmas yang kirim role ini tetap ditolak di service, validasi di sini cuma
            // bentuk umum nilai yang diterima.
            'role' => ['required', 'string', 'in:super_admin,admin_puskesmas,pj_prolanis'],
        ];
    }
}
