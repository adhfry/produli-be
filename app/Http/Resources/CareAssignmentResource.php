<?php

namespace App\Http\Resources;

use App\Services\Visit\CareAssignmentCadenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rencana kunjungan BERULANG (permintaan user, fitur "jadwal kunjungan mendatang" di detail
 * pasien) -- sebelumnya CareAssignment (lihat model & CareAssignmentCadenceService) sama sekali
 * tidak diekspos ke frontend, cadence otomatisnya jalan "diam-diam". upcoming_dates MURNI
 * proyeksi (tidak ada baris VisitAssignment yang benar-benar dibuat untuk tanggal-tanggal itu
 * sampai scan harian benar-benar menjalankannya), lihat CareAssignmentCadenceService::
 * upcomingDates() untuk kenapa ini dihitung ulang tiap request, bukan disimpan.
 */
class CareAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CareAssignmentCadenceService $cadence */
        $cadence = app(CareAssignmentCadenceService::class);

        return [
            'id' => $this->id,
            'worker_type' => $this->worker_type,
            'worker_name' => $this->assigneeUser()?->name,
            'status' => $this->status,
            'cadence_days' => $this->worker_type === 'kader'
                ? (int) config('produli.cadence.kader_days')
                : (int) config('produli.cadence.tenaga_kesehatan_days'),
            'last_triggered_at' => $this->last_triggered_at?->toDateString(),
            'blocked_by_open_visit' => $this->status === 'active' && $cadence->isBlockedByOpenVisit($this->resource),
            'upcoming_dates' => $this->status === 'active'
                ? array_map(fn ($d) => $d->toDateString(), $cadence->upcomingDates($this->resource))
                : [],
        ];
    }
}
