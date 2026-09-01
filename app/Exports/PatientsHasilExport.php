<?php

namespace App\Exports;

use App\Models\PatientsCache;
use App\Services\Patient\HasilPemeriksaanExportService;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * "Download Hasil" versi Excel (dashboard/pasien, permintaan user 2026-09-01) -- pivot 1 baris
 * per pasien, kolom TETAP & URUT per parameter pemeriksaan (lihat
 * HasilPemeriksaanExportService::PARAMETER_COLUMNS). $parameters diambil SEKALI di
 * PatientController::exportHasilExcel() lewat HasilPemeriksaanExportService::columnLabels()
 * SEBELUM kelas ini dibuat -- WithHeadings butuh daftar kolom yang sudah FIXED sebelum baris
 * mana pun diproses, tidak bisa ditentukan sambil jalan dari map().
 *
 * WithChunkReading (BUKAN FromCollection) -- baris ditulis ke file bertahap per chunk, tidak
 * pernah menahan SEMUA pasien+hasil lab di memori sekaligus seperti dompdf. Ini alasan utama
 * kenapa Excel jadi jalur yang direkomendasikan untuk data yang sangat besar (lihat docblock
 * config produli.reports.hasil_pdf_export_max_cells dan PatientController::exportHasilPdf()).
 */
class PatientsHasilExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping
{
    private int $rowNumber = 0;

    /**
     * @param  Builder<PatientsCache>  $query
     * @param  array<int, string>  $parameters
     */
    public function __construct(
        private readonly Builder $query,
        private readonly array $parameters,
        private readonly HasilPemeriksaanExportService $hasilExport,
    ) {}

    public function query(): Builder
    {
        return $this->query->with(['desa', 'kecamatan', 'puskesmas', 'labResults']);
    }

    public function headings(): array
    {
        return array_merge(['No', 'Nama', 'FKTP', 'Desa / Kecamatan'], $this->parameters);
    }

    /**
     * @param  PatientsCache  $patient
     * @return array<int, mixed>
     */
    public function map($patient): array
    {
        // Property instance (BUKAN static) -- setiap unduhan membuat instance BARU lewat
        // Excel::download(), tapi worker PHP-FPM bisa dipakai ulang lintas request, jadi
        // counter statis akan diam-diam terbawa ke unduhan berikutnya dan mulai bukan dari 1.
        $this->rowNumber++;

        $row = [
            $this->rowNumber,
            $patient->nama,
            $this->hasilExport->fktpLabel($patient),
            $this->hasilExport->kelurahanKecamatan($patient),
        ];

        // rowValues() sudah keyBy label DALAM urutan PARAMETER_COLUMNS yang sama persis dgn
        // $this->parameters (headings()) -- array_values() aman, tidak perlu lookup per label.
        return array_merge($row, array_values($this->hasilExport->rowValues($patient->labResults)));
    }

    public function chunkSize(): int
    {
        return 200;
    }
}
