<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitReport extends Model
{
    protected $table = 'visit_reports';

    protected $fillable = [
        'assignment_id',
        'gps_lat',
        'gps_lng',
        'photo_path',
        'exif_meta',
        'face_detected',
        'kondisi',
        'catatan',
        'geo_status',
        'geo_source',
        'latitude',
        'longitude',
        'sync_status',
        'sync_error',
        'synced_at',
        'patient_field_updates',
        'pj_reviewed_by',
        'pj_reviewed_at',
        'validation_status',
        'validated_by',
        'validated_at',
        'validation_note',
        'gda',
        'gdp',
        'gd2jpp',
        'uric_acid',
        'cholesterol',
        'systolic',
        'diastolic',
        'keluhan',
        'tindakan',
        'kepatuhan_obat',
        'sisa_obat',
    ];

    protected function casts(): array
    {
        return [
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'exif_meta' => 'array',
            'patient_field_updates' => 'array',
            'face_detected' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'synced_at' => 'datetime',
            'pj_reviewed_at' => 'datetime',
            'validated_at' => 'datetime',
            'gda' => 'decimal:2',
            'gdp' => 'decimal:2',
            'gd2jpp' => 'decimal:2',
            'uric_acid' => 'decimal:2',
            'cholesterol' => 'decimal:2',
            'systolic' => 'integer',
            'diastolic' => 'integer',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(VisitAssignment::class, 'assignment_id');
    }

    public function pjReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pj_reviewed_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * Kader yang AKTUAL hadir saat kunjungan (docs/planning/02 §16) -- pre-filled dari
     * assignment->companions saat submit, tapi bisa dikoreksi kader primer.
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(VisitReportAttendee::class, 'visit_report_id');
    }
}
