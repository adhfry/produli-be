<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu foto KTP yang diunggah staf + hasil baca OCR-nya. Lihat migrasi create_ktp_scans_table.
 */
class KtpScan extends Model
{
    protected $fillable = [
        'user_id',
        'puskesmas_id',
        'disk',
        'path',
        'mime',
        'size_bytes',
        'ocr_fields',
        'ocr_checks',
        'ocr_model',
        'ocr_error',
        'patients_cache_id',
        'pengiriman_sampel_pasien_id',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'ocr_fields' => 'array',
            'ocr_checks' => 'array',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
