<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabResultCache extends Model
{
    protected $table = 'lab_results_cache';

    protected $fillable = [
        'external_id',
        'patient_id',
        'parameter',
        'value',
        'satuan',
        'nilai_rujukan',
        'class_hasil',
        'validation_status',
        'pengirim',
        'tanggal_periksa',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_periksa' => 'date',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Relasi longgar (bukan FK DB) ke patients_cache.external_patient_id — lihat catatan di migration.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patient_id', 'external_patient_id');
    }
}
