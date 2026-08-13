<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total_patients' => $this->resource->totalPatients,
            'total_patients_prolanis' => $this->resource->totalPatientsProlanis,
            'patients_per_risk_level' => $this->resource->patientsPerRiskLevel,
            'total_assignments' => $this->resource->totalAssignments,
            'visits_per_status' => $this->resource->visitsPerStatus,
            'kader_aktif_count' => $this->resource->kaderAktifCount,
            'tingkat_kepatuhan' => $this->resource->tingkatKepatuhan,
            'aktivitas_hari_ini' => $this->resource->aktivitasHariIni,
            'risiko_per_kecamatan' => $this->resource->risikoPerKecamatan,
            'risiko_per_kecamatan_se_kabupaten' => $this->resource->risikoPerKecamatanSeKabupaten,
            'risiko_per_desa' => $this->resource->risikoPerDesa,
            'puskesmas_performance' => $this->resource->puskesmasPerformance,
            'kecamatan_context' => $this->resource->kecamatanContext,
        ];
    }
}
