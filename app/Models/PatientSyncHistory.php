<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat sinkron per pasien (blueprint docs/planning/16 §4c): satu baris hanya bila ada kolom
 * yang berubah saat PRODULI menarik data pasien dari SiLAKES. changed_fields = {kolom: {lama, baru}}.
 */
class PatientSyncHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['patients_cache_id', 'synced_at', 'sumber', 'silakes_event', 'changed_fields'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'changed_fields' => 'array'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patients_cache_id');
    }
}
