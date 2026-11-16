<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu usulan perubahan field di SiLAKES (patient_field_updates.id) yang diajukan
 * staf PRODULI. Beberapa field dari satu kali "Kirim ke Labkesda" berbagi usulan_id yang sama,
 * supaya pengaju dinotifikasi SEKALI setelah semua field-nya diputuskan.
 */
class SilakesFieldUpdateRef extends Model
{
    protected $fillable = [
        'silakes_update_id', 'usulan_id', 'user_id', 'patients_cache_id',
        'status', 'catatan_reviewer', 'diputuskan_at',
    ];

    protected function casts(): array
    {
        return ['diputuskan_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patients_cache_id');
    }
}
