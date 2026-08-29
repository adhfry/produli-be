<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengantarSampel extends Model
{
    protected $table = 'pengantar_sampel';

    protected $fillable = [
        'user_id',
        'puskesmas_id',
        'status_aktif',
        'no_hp',
        'no_wa',
    ];

    protected function casts(): array
    {
        return [
            'status_aktif' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class);
    }
}
