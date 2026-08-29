<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengirimanSampelLokasi extends Model
{
    protected $table = 'pengiriman_sampel_lokasi';

    protected $fillable = [
        'pengiriman_sampel_id',
        'latitude',
        'longitude',
        'accuracy',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function pengirimanSampel(): BelongsTo
    {
        return $this->belongsTo(PengirimanSampel::class);
    }
}
