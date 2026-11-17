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
        'no_bpjs',
        'nik_hash',
        'nik',
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
        'pengirim_raw',
        'geo_status',
        'latitude',
        'longitude',
        'geo_source',
        'geo_verified_by',
        'geo_verified_at',
        'last_synced_at',
        // Fase 2 blueprint docs/planning/16 (2026-11-17)
        'tempat_lahir',
        'golongan_darah',
        'agama',
        'status_perkawinan',
        'pekerjaan',
        'is_bpjs',
        'fktp',
        'email',
        'domisili_alamat',
        'domisili_rt_rw',
        'domisili_kel_desa',
        'domisili_kecamatan',
        'domisili_sumber',
        'domisili_updated_at',
        'silakes_updated_at',
        'refreshed_at',
        'refreshed_via',
        'puskesmas_locked_at',
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
            'is_bpjs' => 'boolean',
            'domisili_updated_at' => 'datetime',
            'silakes_updated_at' => 'datetime',
            'refreshed_at' => 'datetime',
            'puskesmas_locked_at' => 'datetime',
        ];
    }

    /**
     * Puskesmas pasien tidak dihitung ulang oleh sinkron maupun produli:reresolve-wilayah /
     * reconcile-wilayah: ditetapkan manual (override), atau sudah dikunci sejak pertama kali
     * mendapat puskesmas (blueprint §4d, keputusan §9 no. 2). Pindah hanya lewat override.
     */
    public function puskesmasTerkunci(): bool
    {
        return $this->puskesmas_resolution_method === 'manual'
            || ($this->puskesmas_locked_at !== null && $this->puskesmas_id !== null);
    }

    public function syncHistories(): HasMany
    {
        return $this->hasMany(PatientSyncHistory::class, 'patients_cache_id');
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

    // Rencana kunjungan berulang AKTIF (permintaan user, fitur jadwal cadence -- lihat
    // CareAssignmentResource) -- 'active' saja, plan yang sudah 'completed'/dibatalkan tidak
    // relevan ditampilkan sebagai jadwal MENDATANG.
    public function activeCareAssignments(): HasMany
    {
        return $this->hasMany(CareAssignment::class, 'patient_id')->where('status', 'active');
    }
}
