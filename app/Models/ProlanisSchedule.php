<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProlanisSchedule extends Model
{
    protected $fillable = [
        'patient_id',
        'puskesmas_id',
        'jenis_prolanis',
        'source_lab_date',
        'scheduled_date',
        'is_manual_override',
        'status',
        'notified_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'source_lab_date' => 'date',
            'scheduled_date' => 'date',
            'is_manual_override' => 'boolean',
            'notified_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patient_id');
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class, 'puskesmas_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
