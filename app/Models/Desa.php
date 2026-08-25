<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Desa extends Model
{
    protected $table = 'desa';

    protected $fillable = [
        'kecamatan_id',
        'puskesmas_id',
        'kode_kemendagri',
        'nama',
        'latitude',
        'longitude',
        'boundary',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            // Array koordinat [lng,lat] GeoJSON Polygon (bisa multi-ring, lihat
            // WilayahResolver::resolveByCoordinates()) -- diisi produli:import-desa-boundaries.
            'boundary' => 'array',
        ];
    }

    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class);
    }

    public function wilayahMappings(): HasMany
    {
        return $this->hasMany(WilayahMapping::class);
    }
}
