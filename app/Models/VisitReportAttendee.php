<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kehadiran AKTUAL kader saat kunjungan (docs/planning/02 §16) -- beda dari
 * VisitAssignmentCompanion (itu RENCANA saat assignment dibuat). Pre-filled dari companion,
 * tapi bisa dikoreksi kader primer sebelum submit laporan.
 */
class VisitReportAttendee extends Model
{
    protected $table = 'visit_report_attendees';

    /**
     * Cuma created_at (docs §16), tidak ada updated_at -- baris ini tidak pernah di-update
     * setelah dibuat.
     */
    public $timestamps = false;

    protected $fillable = [
        'visit_report_id',
        'kader_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function visitReport(): BelongsTo
    {
        return $this->belongsTo(VisitReport::class, 'visit_report_id');
    }

    public function kader(): BelongsTo
    {
        return $this->belongsTo(Kader::class);
    }
}
