<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PatientsCache extends Model
{
    protected $table = 'patients_cache';

    protected $fillable = [
        'external_patient_id',
        'no_reg',
        'nik_hash',
        'nama',
        'gender',
        'tgl_lahir',
        'phone',
        'alamat',
        'rt_rw',
        'kel_desa_raw',
        'kecamatan_raw',
        'is_prolanis',
        'jenis_prolanis',
        'is_perokok',
        'jenis_perokok',
        'desa_id',
        'kecamatan_id',
        'wilayah_status',
        'puskesmas_id',
        'puskesmas_resolution_method',
        'geo_status',
        'latitude',
        'longitude',
        'geo_source',
        'geo_verified_by',
        'geo_verified_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tgl_lahir' => 'date',
            'is_prolanis' => 'boolean',
            'is_perokok' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geo_verified_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function desa(): BelongsTo
    {
        return $this->belongsTo(Desa::class);
    }

    /**
     * Kecamatan hasil match WilayahResolver -- BISA terisi walau desa_id NULL (kecamatan
     * dikenali tapi desa belum, kasus umum ~19,6% pasien, lihat migration
     * add_kecamatan_id_to_patients_cache_table). JANGAN diturunkan cuma lewat desa.kecamatan_id,
     * itu akan hilang persis utk populasi yang justru butuh field ini.
     */
    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class);
    }

    public function labResults(): HasMany
    {
        return $this->hasMany(LabResultCache::class, 'patient_id', 'external_patient_id');
    }

    public function riskClassifications(): HasMany
    {
        return $this->hasMany(RiskClassification::class, 'patient_id');
    }

    /**
     * Hindari subquery MAX(computed_at) -- pakai flag is_latest yang sudah dijaga
     * RiskClassificationService (sama alasan seperti riskClassifications() di atas).
     */
    public function latestRiskClassification(): HasOne
    {
        return $this->hasOne(RiskClassification::class, 'patient_id')->where('is_latest', true);
    }

    public function visitAssignments(): HasMany
    {
        return $this->hasMany(VisitAssignment::class, 'patient_id');
    }
}
