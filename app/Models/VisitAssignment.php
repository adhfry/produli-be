<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VisitAssignment extends Model
{
    protected $table = 'visit_assignments';

    protected $fillable = [
        'patient_id',
        'kader_id',
        'assigned_by',
        'scheduled_date',
        'status',
        'priority',
        'assignment_method',
        'puskesmas_id_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
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

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Snapshot puskesmas SAAT assignment dibuat — jangan pernah di-update setelah create()
     * (docs/planning/02 §2a). Nama relasi sengaja beda dari kolom biar tidak kepencet ->save()
     * tanpa sadar lewat mass-assignment biasa.
     */
    public function puskesmasSnapshot(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class, 'puskesmas_id_snapshot');
    }

    public function visitReports(): HasMany
    {
        return $this->hasMany(VisitReport::class, 'assignment_id');
    }

    /**
     * Laporan TERBARU untuk assignment ini -- bisa lebih dari satu baris seiring waktu (alur
     * "laporan invalid -> assignment kembali pending -> kader submit ulang", docs/planning/02
     * §11), jadi dashboard/kunjungan & /app/tugas selalu tampilkan yang ini, bukan visitReports()
     * mentah (yang bisa berisi percobaan lama yang sudah tidak relevan).
     */
    public function latestReport(): HasOne
    {
        return $this->hasOne(VisitReport::class, 'assignment_id')->latestOfMany();
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class, 'assignment_id');
    }

    /**
     * Kader pendamping RENCANA (docs/planning/02 §16) -- diisi lewat POST .../bulk yang
     * diperluas, beda dari kader_id (primer) yang menentukan progress/kepatuhan.
     */
    public function companions(): HasMany
    {
        return $this->hasMany(VisitAssignmentCompanion::class, 'assignment_id');
    }
}
