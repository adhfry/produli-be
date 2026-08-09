<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rencana kader pendamping saat assignment dibuat (docs/planning/02 §16) -- beda dari
 * VisitReportAttendee (itu kehadiran AKTUAL saat submit laporan).
 */
class VisitAssignmentCompanion extends Model
{
    protected $table = 'visit_assignment_companions';

    /**
     * Cuma created_at (docs §16), tidak ada updated_at -- baris ini tidak pernah di-update
     * setelah dibuat.
     */
    public $timestamps = false;

    protected $fillable = [
        'assignment_id',
        'kader_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(VisitAssignment::class, 'assignment_id');
    }

    public function kader(): BelongsTo
    {
        return $this->belongsTo(Kader::class);
    }
}
