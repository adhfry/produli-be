<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenagaKesehatan extends Model
{
    protected $table = 'tenaga_kesehatan';

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

    public function pj(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pj_id');
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class);
    }

    public function careAssignments(): HasMany
    {
        return $this->hasMany(CareAssignment::class);
    }

    public function visitAssignments(): HasMany
    {
        return $this->hasMany(VisitAssignment::class);
    }
}
