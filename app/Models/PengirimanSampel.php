<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PengirimanSampel extends Model
{
    use SoftDeletes;

    protected $table = 'pengiriman_sampel';

    protected $fillable = [
        'puskesmas_id',
        'status',
        'dibuat_oleh',
        'dikunci_at',
        'dikunci_oleh',
        'pengantar_sampel_id',
        'ditugaskan_at',
        'ditugaskan_oleh',
        'otw_at',
        'tiba_at',
        'foto_bukti_path',
        'tiba_gps_lat',
        'tiba_gps_lng',
        'tiba_gps_accuracy',
        'dikonfirmasi_labkesda_at',
        'dikonfirmasi_labkesda_oleh',
        'silakes_batch_ref',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'dikunci_at' => 'datetime',
            'ditugaskan_at' => 'datetime',
            'otw_at' => 'datetime',
            'tiba_at' => 'datetime',
            'dikonfirmasi_labkesda_at' => 'datetime',
            'tiba_gps_lat' => 'decimal:7',
            'tiba_gps_lng' => 'decimal:7',
            'tiba_gps_accuracy' => 'decimal:2',
        ];
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class);
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function dikunciOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikunci_oleh');
    }

    public function pengantarSampel(): BelongsTo
    {
        return $this->belongsTo(PengantarSampel::class);
    }

    public function ditugaskanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditugaskan_oleh');
    }

    public function pasien(): HasMany
    {
        return $this->hasMany(PengirimanSampelPasien::class)->orderBy('urutan');
    }

    public function lokasi(): HasOne
    {
        return $this->hasOne(PengirimanSampelLokasi::class);
    }

    /**
     * Mirror persis VisitReport::photoUrl() -- presigned temporary URL (BUKAN publik permanen),
     * graceful null (bukan exception) kalau disk tidak mendukung temporaryUrl().
     */
    public function fotoBuktiUrl(): ?string
    {
        if ($this->foto_bukti_path === null) {
            return null;
        }

        $disk = (string) config('produli.storage.visit_photos_disk', 's3');

        try {
            return Storage::disk($disk)->temporaryUrl($this->foto_bukti_path, now()->addMinutes(15));
        } catch (Throwable $e) {
            Log::warning('PengirimanSampel::fotoBuktiUrl gagal generate temporary URL', [
                'pengiriman_sampel_id' => $this->id,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
