<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kondisi satu pasien pada satu hari (blueprint docs/planning/16 §4c), dibuat produli:snapshot-harian. */
class PatientDailySnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'snapshot_date', 'patients_cache_id', 'puskesmas_id', 'kecamatan_id', 'desa_id',
        'risk_level', 'risk_classification_id', 'early_detection_flag', 'tanggal_lab_terakhir',
    ];

    protected function casts(): array
    {
        // snapshot_date SENGAJA tanpa cast date: disimpan & dicari sebagai 'Y-m-d' polos supaya
        // pencarian per tanggal memakai indeks dan sama di MySQL maupun SQLite (test).
        return ['tanggal_lab_terakhir' => 'date', 'early_detection_flag' => 'boolean'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientsCache::class, 'patients_cache_id');
    }
}
