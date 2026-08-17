<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VisitReport extends Model
{
    protected $table = 'visit_reports';

    protected $fillable = [
        'assignment_id',
        'client_submission_id',
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
        'cara_rujukan',
        'rujukan_status',
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
            'tindakan' => 'array',
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

    /**
     * URL sementara (15 menit) untuk foto bukti kunjungan -- SEBELUMNYA sengaja tidak pernah
     * diekspos sama sekali (lihat docblock lama VisitReportResource, "di luar scope task ini"),
     * sekarang jadi kebutuhan nyata halaman detail kunjungan (dashboard/kunjungan/[id].vue).
     * temporaryUrl() presigned (BUKAN URL publik permanen) -- foto pasien data sensitif, expiry
     * pendek mencegah link ditempel/dibagikan lalu tetap bisa diakses lama setelahnya.
     *
     * Graceful null (BUKAN exception) kalau disk driver tidak mendukung temporaryUrl() (mis.
     * 'local' di beberapa environment dev) -- konsisten dgn filosofi FcmService/opsional lain di
     * codebase ini: foto tidak tampil itu jauh lebih baik daripada endpoint detail 500 total.
     */
    public function photoUrl(): ?string
    {
        if ($this->photo_path === null) {
            return null;
        }

        $disk = (string) config('produli.storage.visit_photos_disk', 's3');

        try {
            return Storage::disk($disk)->temporaryUrl($this->photo_path, now()->addMinutes(15));
        } catch (Throwable $e) {
            Log::warning('VisitReport::photoUrl gagal generate temporary URL', [
                'visit_report_id' => $this->id,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
