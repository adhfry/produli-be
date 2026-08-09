<?php

namespace App\Services\Patient;

use App\Models\PatientsCache;
use App\Models\User;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query list pasien ter-scope role (docs/planning/02 §7) -- bentuk QUERY dari aturan yang sama
 * dengan App\Policies\Concerns\ScopesByPuskesmas (cek per-record). Kalau aturan scope berubah,
 * update juga trait itu (dan sebaliknya) supaya list endpoint dan cek akses per-record tidak
 * pernah berbeda.
 */
class PatientQueryService
{
    /**
     * @return Builder<PatientsCache>
     */
    public function scopedQuery(User $user): Builder
    {
        if (DataScope::isFullAccess($user)) {
            return PatientsCache::query();
        }

        if (DataScope::isKaderOnly($user)) {
            $kader = $user->kader;

            if ($kader === null) {
                return PatientsCache::query()->whereRaw('1 = 0');
            }

            return PatientsCache::query()->whereHas(
                'visitAssignments',
                fn (Builder $query) => $query->where('kader_id', $kader->id)
            );
        }

        if ($user->puskesmas_id === null) {
            return PatientsCache::query()->whereRaw('1 = 0');
        }

        return PatientsCache::query()->where('puskesmas_id', $user->puskesmas_id);
    }
}
