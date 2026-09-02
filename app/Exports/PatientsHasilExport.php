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
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "Download Hasil" versi Excel (dashboard/pasien, permintaan user 2026-09-01/02) -- pivot 1
 * baris per pasien, kolom TETAP & URUT per parameter pemeriksaan (lihat
 * HasilPemeriksaanExportService::PARAMETER_COLUMNS). $parameters diambil SEKALI di
 * PatientController::exportHasilExcel() lewat HasilPemeriksaanExportService::columnLabels()
 * SEBELUM kelas ini dibuat -- WithHeadings butuh daftar kolom yang sudah FIXED sebelum baris
 * mana pun diproses, tidak bisa ditentukan sambil jalan dari map().
 *
 * Sel Nama (NIK sbg sub-baris) & sel parameter (nilai merah kalau abnormal + baris BN, satuan
 * & BN tetap hitam) dibangun sbg RichText (bukan string polos) -- PhpSpreadsheet menerima
 * RichText langsung sbg isi sel, itu satu-satunya cara mewarnai SEBAGIAN teks dalam 1 sel.
 * WithStyles mengaktifkan wrap-text di kolom-kolom itu supaya baris kedua (NIK/BN) benar-benar
 * tampil sbg baris baru, bukan karakter newline mentah.
 *
 * WithChunkReading (BUKAN FromCollection) -- baris ditulis ke file bertahap per chunk, tidak
 * pernah menahan SEMUA pasien+hasil lab di memori sekaligus seperti dompdf. Ini alasan utama
 * kenapa Excel jadi jalur yang direkomendasikan untuk data yang sangat besar (lihat docblock
 * config produli.reports.hasil_pdf_export_max_cells dan PatientController::exportHasilPdf()).
 */
class PatientsHasilExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping, WithStyles
{
    private const ABNORMAL_RGB = 'DC2626';

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
            $this->buildNamaCell($patient),
            $this->hasilExport->fktpLabel($patient),
            $this->hasilExport->kelurahanKecamatan($patient),
        ];

        // rowCellData() sudah keyBy label DALAM urutan PARAMETER_COLUMNS yang sama persis dgn
        // $this->parameters (headings()) -- array_values() aman, tidak perlu lookup per label.
        foreach ($this->hasilExport->rowCellData($patient->labResults) as $cellData) {
            $row[] = $this->buildParameterCell($cellData);
        }

        return $row;
    }

    /**
     * Nama pasien + NIK sbg sub-baris (permintaan user) -- italic HANYA saat NIK tidak
     * diketahui, teks tetap hitam (cuma nama & NIK, tidak ada pewarnaan merah di sel ini).
     */
    private function buildNamaCell(PatientsCache $patient): RichText
    {
        $nik = $this->hasilExport->nikSubline($patient);

        $rich = new RichText;
        $rich->createText($patient->nama);
        $rich->createText("\n");
        $nikRun = $rich->createTextRun($nik['text']);
        $nikRun->getFont()->setSize(8);
        $nikRun->getFont()->setItalic($nik['italic']);

        return $rich;
    }

    /**
     * "-" kalau pasien belum pernah diperiksa parameter ini. Kalau ada: nilai hasil MERAH
     * kalau abnormal (permintaan user), lalu satuan (spasi, sebaris) & baris BN (batas normal
     * = nilai_rujukan asli SiLAKES) TETAP HITAM apa pun status nilainya -- 2 run terpisah,
     * BUKAN 1 run yang ikut merah semua, itu sebabnya sel ini RichText bukan string biasa.
     *
     * @param  array{value: ?string, satuan: ?string, bn: ?string, abnormal: bool}  $cellData
     */
    private function buildParameterCell(array $cellData): string|RichText
    {
        if ($cellData['value'] === null) {
            return '-';
        }

        $rich = new RichText;

        $valueRun = $rich->createTextRun($cellData['value']);
        if ($cellData['abnormal']) {
            $valueRun->getFont()->getColor()->setRGB(self::ABNORMAL_RGB);
            $valueRun->getFont()->setBold(true);
        }

        if ($cellData['satuan']) {
            $rich->createText(' '.$cellData['satuan']);
        }

        if ($cellData['bn']) {
            $rich->createText("\n");
            $bnRun = $rich->createTextRun('BN: '.$cellData['bn']);
            $bnRun->getFont()->setSize(8);
        }

        return $rich;
    }

    /**
     * Wrap-text di kolom Nama & seluruh kolom parameter -- tanpa ini karakter "\n" di RichText
     * di atas cuma tersimpan sbg data, TIDAK tampil sbg baris baru saat file dibuka (perilaku
     * default Excel: butuh wrap-text eksplisit per sel/kolom). Diterapkan ke SELURUH kolom
     * (bukan per-baris, tidak tahu jumlah baris akhir dari FromQuery+WithChunkReading) --
     * kolom lain (No/FKTP/Desa) tidak berisi RichText multi-baris jadi wrap-text di situ no-op.
     */
    public function styles(Worksheet $sheet): array
    {
        $namaColumn = Coordinate::stringFromColumnIndex(2);
        $sheet->getStyle("{$namaColumn}:{$namaColumn}")->getAlignment()->setWrapText(true);

        if ($this->parameters !== []) {
            $firstParamColumn = Coordinate::stringFromColumnIndex(5);
            $lastParamColumn = Coordinate::stringFromColumnIndex(4 + count($this->parameters));
            $sheet->getStyle("{$firstParamColumn}:{$lastParamColumn}")->getAlignment()->setWrapText(true);
        }

        return [];
    }

    public function chunkSize(): int
    {
        return 200;
    }
}
