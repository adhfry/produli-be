<?php

namespace App\Http\Requests\Announcement;

use Illuminate\Foundation\Http\FormRequest;

class CreateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (SystemAnnouncementPolicy::create) di controller.
        return true;
    }

    /**
     * Daftar role valid -- HARUS sinkron dengan Role union type di frontend (app/types/api.ts)
     * dan role yang benar-benar terdaftar di RolesSeeder. Tidak divalidasi via Spatie Role model
     * (query DB) -- daftar tetap kecil & jarang berubah, hardcode di sini lebih sederhana dan
     * error validasinya lebih jelas (in:...) daripada exists:roles,name.
     *
     * @var array<int, string>
     */
    private const VALID_ROLES = ['super_admin', 'admin_puskesmas', 'pj_prolanis', 'kader', 'tenaga_kesehatan'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string'],
            'urgency' => ['required', 'string', 'in:info,penting,darurat'],
            'icon' => ['nullable', 'string', 'max:60'],
            // Kunci warna tema terbatas (bukan hex bebas) -- lihat migration
            // alter_system_announcements_table_add_rich_content untuk alasan.
            'color' => ['nullable', 'string', 'in:primary,secondary,success,warning,danger,info,accent'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'button_label' => ['nullable', 'string', 'max:60', 'required_with:button_url'],
            'button_url' => ['nullable', 'url', 'max:500', 'required_with:button_label'],
            'target_roles' => ['nullable', 'array'],
            'target_roles.*' => ['string', 'in:'.implode(',', self::VALID_ROLES)],
        ];
    }
}
