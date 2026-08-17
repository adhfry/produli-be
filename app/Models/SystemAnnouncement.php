<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemAnnouncement extends Model
{
    protected $table = 'system_announcements';

    protected $fillable = [
        'title',
        'description',
        'urgency',
        'icon',
        'color',
        'image_url',
        'button_label',
        'button_url',
        'target_roles',
        'posted_by',
    ];

    protected function casts(): array
    {
        return [
            // null/[] = semua role -- lihat isTargetedTo().
            'target_roles' => 'array',
        ];
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class, 'announcement_id');
    }

    /**
     * target_roles null ATAU array kosong = tampil ke SEMUA role (perilaku default/lama).
     * Array berisi = HANYA role yang disebut, dicocokkan terhadap SELURUH role user (dual-role
     * kader+pj_prolanis dkk cocok kalau SALAH SATU rolenya ada di target_roles).
     *
     * @param  array<int, string>  $userRoles
     */
    public function isTargetedTo(array $userRoles): bool
    {
        if (empty($this->target_roles)) {
            return true;
        }

        return count(array_intersect($this->target_roles, $userRoles)) > 0;
    }
}
