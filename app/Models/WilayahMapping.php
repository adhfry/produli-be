<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WilayahMapping extends Model
{
    protected $table = 'wilayah_mapping';

    protected $fillable = [
        'kel_desa_raw',
        'kecamatan_raw',
        'desa_id',
        'status',
        'matched_at',
        'matched_by',
    ];

    protected function casts(): array
    {
        return [
            'matched_at' => 'datetime',
        ];
    }

    public function desa(): BelongsTo
    {
        return $this->belongsTo(Desa::class);
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
