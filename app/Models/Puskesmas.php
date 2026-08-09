<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Puskesmas extends Model
{
    protected $table = 'puskesmas';

    protected $fillable = [
        'kabupaten_id',
        'kecamatan_id',
        'kode_internal',
        'nama',
        'alamat',
        'status_aktif',
        'no_telp',
        'no_wa',
        'latitude',
        'longitude',
        'deskripsi',
    ];

    protected function casts(): array
    {
        return [
            'status_aktif' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function kabupaten(): BelongsTo
    {
        return $this->belongsTo(Kabupaten::class);
    }

    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function desa(): HasMany
    {
        return $this->hasMany(Desa::class);
    }
}
