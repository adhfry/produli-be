<?php

namespace App\DTO;

/**
 * Ringkasan dashboard ter-scope role (docs/planning/02 §7/§13/§17) -- jumlah pasien per level
 * risiko & jumlah assignment kunjungan per status, dalam scope data yang sama dengan yang
 * dipakai GET /api/v1/patients (App\Services\Patient\PatientQueryService) dan
 * GET /api/v1/visit-assignments (App\Services\Visit\VisitAssignmentService).
 */
final class DashboardSummary
{
    /**
     * @param  array<string, int>  $patientsPerRiskLevel  kunci: ringan/sedang/berat
     * @param  array<string, int>  $visitsPerStatus  kunci: pending/in_progress/completed/cancelled
     * @param  array<int, array{kader_id: int, nama: ?string, target_hari_ini: int, selesai_hari_ini: int, last_update_at: ?string}>  $aktivitasHariIni
     * @param  array<int, array{kecamatan_id: int, kecamatan_nama: string, kecamatan_kode: ?string, ringan: int, sedang: int, berat: int}>  $risikoPerKecamatan
     * @param  array<int, array{desa_id: int, desa_nama: string, desa_kode: ?string, ringan: int, sedang: int, berat: int}>  $risikoPerDesa
     */
    public function __construct(
        public readonly int $totalPatients,
        public readonly array $patientsPerRiskLevel,
        public readonly int $totalAssignments,
        public readonly array $visitsPerStatus,
        public readonly int $kaderAktifCount,
        public readonly float $tingkatKepatuhan,
        public readonly array $aktivitasHariIni,
        public readonly array $risikoPerKecamatan,
        public readonly array $risikoPerDesa,
    ) {}
}
