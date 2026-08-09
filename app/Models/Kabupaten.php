<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kabupaten extends Model
{
    protected $table = 'kabupaten';

    protected $fillable = [
        'kode_kemendagri',
        'nama',
    ];

    public function kecamatan(): HasMany
    {
        return $this->hasMany(Kecamatan::class);
    }

    public function puskesmas(): HasMany
    {
        return $this->hasMany(Puskesmas::class);
    }
}
