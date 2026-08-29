<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengirimanSampelPasien extends Model
{
    protected $table = 'pengiriman_sampel_pasien';

    protected $fillable = [
        'pengiriman_sampel_id',
        'external_patient_id',
        'registration_proposal_ref',
        'nama_snapshot',
        'jenis_prolanis_snapshot',
        'urutan',
        'data_pasien_baru_nik',
        'data_pasien_baru_gender',
        'data_pasien_baru_tempat_lahir',
        'data_pasien_baru_tgl_lahir',
        'data_pasien_baru_phone',
        'data_pasien_baru_alamat',
        'data_pasien_baru_rt_rw',
        'data_pasien_baru_kel_desa',
        'data_pasien_baru_kecamatan',
        'data_pasien_baru_no_bpjs',
    ];

    protected function casts(): array
    {
        return [
            'data_pasien_baru_tgl_lahir' => 'date',
        ];
    }

    public function isPasienBaru(): bool
    {
        return $this->external_patient_id === null;
    }

    public function pengirimanSampel(): BelongsTo
    {
        return $this->belongsTo(PengirimanSampel::class);
    }

    /**
     * Referensi LONGGAR ke patients_cache.external_patient_id (bukan FK DB, lihat docblock
     * migrasi) -- relasi ini murni kemudahan query, TIDAK menjamin integritas referensial.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'external_patient_id', 'external_patient_id');
    }
}
