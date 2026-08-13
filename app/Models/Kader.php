<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kader extends Model
{
    protected $table = 'kader';

    protected $fillable = [
        'user_id',
        'pj_id',
        'puskesmas_id',
        'status_aktif',
        'no_hp',
        'no_wa',
        'alamat',
        'gender',
        'tgl_lahir',
    ];

    protected function casts(): array
    {
        return [
            'status_aktif' => 'boolean',
            'tgl_lahir' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * PJ Prolanis yang mengawasi kader ini — user langsung (bukan self-referensi ke kader.id),
     * karena PJ yang murni supervisor (tidak pernah kunjungan sendiri) tidak akan punya baris
     * di tabel kader. Kalau PJ itu kebetulan merangkap kader, ambil profil kader-nya lewat
     * $kader->pj->kader (User::kader()), bukan lewat relasi ini.
     */
    public function pj(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pj_id');
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class);
    }

    public function visitAssignments(): HasMany
    {
        return $this->hasMany(VisitAssignment::class);
    }

    public function careAssignments(): HasMany
    {
        return $this->hasMany(CareAssignment::class);
    }
}
