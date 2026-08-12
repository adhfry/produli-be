<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CareAssignment extends Model
{
    protected $table = 'care_assignments';

    protected $fillable = [
        'patient_id',
        'worker_type',
        'kader_id',
        'tenaga_kesehatan_id',
        'assigned_by',
        'puskesmas_id_snapshot',
        'status',
        'last_triggered_at',
        'last_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_triggered_at' => 'date',
            'last_completed_at' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patient_id');
    }

    public function kader(): BelongsTo
    {
        return $this->belongsTo(Kader::class);
    }

    public function tenagaKesehatan(): BelongsTo
    {
        return $this->belongsTo(TenagaKesehatan::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function puskesmasSnapshot(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class, 'puskesmas_id_snapshot');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(VisitAssignment::class, 'care_assignment_id');
    }

    /**
     * User yang benar-benar ditugaskan di plan ini, terlepas dari worker_type -- pemanggil
     * (mis. NotifyService) tidak perlu tahu apakah ini kader atau tenaga_kesehatan.
     */
    public function assigneeUser(): ?User
    {
        return $this->worker_type === 'kader'
            ? $this->kader?->user
            : $this->tenagaKesehatan?->user;
    }
}
