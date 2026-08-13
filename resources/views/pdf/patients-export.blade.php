<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    {{-- Carlito -- pengganti open-source resmi Calibri (metrik identik, lisensi SIL OFL),
    dipakai KHUSUS di kop laporan (permintaan eksplisit: "khusus header fontnya"), bukan
    seluruh dokumen. File TTF-nya ditaruh manual di resources/fonts/carlito/ (belum ada di
    repo, agent tidak bisa membuat file font biner -- lihat README di folder itu). Fallback
    'DejaVu Sans' otomatis kepakai kalau file belum ada, PDF tetap ter-generate normal. --}}
    @font-face { font-family: 'Carlito'; font-weight: normal; font-style: normal; src: url('{{ resource_path('fonts/carlito/Carlito-Regular.ttf') }}'); }
    @font-face { font-family: 'Carlito'; font-weight: bold; font-style: normal; src: url('{{ resource_path('fonts/carlito/Carlito-Bold.ttf') }}'); }
    @font-face { font-family: 'Carlito'; font-weight: normal; font-style: italic; src: url('{{ resource_path('fonts/carlito/Carlito-Italic.ttf') }}'); }
    @font-face { font-family: 'Carlito'; font-weight: bold; font-style: italic; src: url('{{ resource_path('fonts/carlito/Carlito-BoldItalic.ttf') }}'); }

    @page { margin: 20px 24px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1e293b; }

    .letterhead { width: 100%; border-bottom: 2px solid #0f766e; padding-bottom: 8px; margin-bottom: 10px; font-family: 'Carlito', 'DejaVu Sans', sans-serif; }
    .letterhead td { vertical-align: middle; }
    .letterhead img.logo-sumenep { height: 40px; }
    .letterhead img.logo-produli { height: 42px; }
    .letterhead .org-parent { font-size: 8.5px; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.3px; }
    .letterhead .org-name { font-size: 13.5px; font-weight: bold; color: #0f172a; margin-top: 1px; }
    .letterhead .org-sub { font-size: 8.5px; color: #64748b; margin-top: 2px; }
    .letterhead .report-title { text-align: right; font-size: 13px; font-weight: bold; color: #0f766e; }
    .letterhead .report-scope { text-align: right; font-size: 8.5px; font-weight: 600; color: #334155; margin-top: 2px; }
    .letterhead .report-meta { text-align: right; font-size: 8px; color: #64748b; margin-top: 2px; }

    table.data { width: 100%; border-collapse: collapse; }
    table.data th {
        background: #0f766e; color: #ffffff; font-size: 8px; text-transform: uppercase;
        padding: 5px 4px; text-align: left; border: 1px solid #0f766e;
    }
    table.data td { padding: 4px; border: 1px solid #e2e8f0; font-size: 8.5px; }
    table.data tr:nth-child(even) td { background: #f8fafc; }

    .badge { padding: 1px 5px; border-radius: 3px; font-size: 7.5px; font-weight: bold; text-transform: uppercase; }
    .badge-berat { background: #fee2e2; color: #b91c1c; }
    .badge-sedang { background: #fef3c7; color: #b45309; }
    .badge-ringan { background: #dcfce7; color: #15803d; }
    .badge-tidak_berisiko { background: #dbeafe; color: #1d4ed8; }
    .badge-null { background: #f1f5f9; color: #64748b; }

    {{-- Smart Early Detection (revisi Bu Kadis) -- notice kecil di bawah badge risiko Sedang
    yang nilainya mendekati ambang Berat dan/atau tren memburuk, lihat footnote di akhir
    halaman untuk penjelasan lengkap. --}}
    .early-detection-notice { display: block; margin-top: 2px; font-size: 7px; font-weight: bold; color: #b45309; }

    .footer { margin-top: 10px; font-size: 7.5px; color: #94a3b8; text-align: right; }
    .footnote { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 7.5px; color: #64748b; line-height: 1.5; }
    .footnote .footnote-icon { color: #b45309; font-weight: bold; }
</style>
</head>
<body>

@php
    $riskLabels = [
        'berat' => 'Risiko Berat',
        'sedang' => 'Risiko Sedang',
        'ringan' => 'Risiko Ringan',
        'tidak_berisiko' => 'Tidak Berisiko',
    ];
    $hasEarlyDetectionPatients = $patients->contains(
        fn ($patient) => (bool) ($patient->latestRiskClassification?->early_detection_flag ?? false)
    );
@endphp

<table class="letterhead">
    <tr>
        <td style="width: 46px;">
            <img class="logo-sumenep" src="{{ \App\Support\PdfAssets::sumenepLogoDataUri() }}" alt="Logo Kabupaten Sumenep">
        </td>
        <td style="width: 60px;">
            <img class="logo-produli" src="{{ \App\Support\MailBranding::logoDataUri() }}" alt="Logo PRODULI">
        </td>
        <td>
            <div class="org-parent">Dinas Kesehatan, Pengendalian Penduduk dan Keluarga Berencana</div>
            <div class="org-name">UPTD Laboratorium Kesehatan Daerah</div>
            <div class="org-sub">Kabupaten Sumenep &mdash; Sistem PRODULI (Prolanis Peduli)</div>
        </td>
        <td>
            <div class="report-title">Laporan Data Pasien Prolanis</div>
            @if ($filterSummary)
                <div class="report-scope">{{ $filterSummary }}</div>
            @endif
            <div class="report-meta">
                Diunduh oleh {{ $generatedBy->name }} &middot; {{ $generatedAt->translatedFormat('d F Y, H:i') }} WIB<br>
                Total {{ number_format($totalCount, 0, ',', '.') }} pasien
            </div>
        </td>
    </tr>
</table>

<table class="data">
    <thead>
        <tr>
            <th style="width: 4%;">No</th>
            <th style="width: 16%;">Nama</th>
            <th style="width: 8%;">NIK</th>
            <th style="width: 6%;">Usia/JK</th>
            <th style="width: 18%;">Alamat</th>
            <th style="width: 10%;">Kecamatan / Desa</th>
            <th style="width: 12%;">Puskesmas</th>
            <th style="width: 8%;">Status Prolanis</th>
            <th style="width: 9%;">Tingkat Risiko</th>
            <th style="width: 9%;">Status Wilayah</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($patients as $index => $patient)
            @php
                // diffInYears() balikin float (mis. "56.283710865058"), bukan cuma soal
                // pembulatan -- tgl_lahir yang tidak lengkap/kosong dari SiLAKES sering jatuh ke
                // nilai default mendekati hari ini, menghasilkan usia mendekati 0 tahun. Usia
                // di bawah 5 tahun untuk populasi Prolanis (target lansia/penyakit kronis)
                // hampir pasti data tidak lengkap, bukan usia sungguhan -- tampilkan "Tidak
                // Diketahui" alih-alih angka yang menyesatkan.
                $ageYears = $patient->tgl_lahir ? (int) floor($patient->tgl_lahir->diffInYears(now())) : null;
                $ageDisplay = ($ageYears !== null && $ageYears >= 5) ? $ageYears.' th' : 'Tidak Diketahui';
                $level = $patient->latestRiskClassification?->level;
                $isEarlyDetection = (bool) ($patient->latestRiskClassification?->early_detection_flag ?? false);
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $patient->nama }}</td>
                <td>{{ \App\Support\NikDisplay::resolve($patient->nik) }}</td>
                <td>{{ $ageDisplay }} / {{ $patient->gender ?? '-' }}</td>
                <td>{{ $patient->alamat ?? '-' }}</td>
                <td>{{ $patient->kecamatan?->nama ?? '-' }} / {{ $patient->desa?->nama ?? '-' }}</td>
                <td>
                    @if ($patient->puskesmas?->nama)
                        {{ $patient->puskesmas->nama }}
                    @elseif ($patient->puskesmas_resolution_method === 'pengirim_individual' && $patient->pengirim_raw)
                        Rujukan: {{ $patient->pengirim_raw }}
                    @else
                        Belum Teridentifikasi
                    @endif
                </td>
                <td>{{ $patient->jenis_prolanis ?? ($patient->is_prolanis ? 'Belum Diketahui' : '-') }}</td>
                <td>
                    <span class="badge badge-{{ $level ?? 'null' }}">{{ $riskLabels[$level] ?? 'Belum Dihitung' }}</span>
                    @if ($isEarlyDetection)
                        <span class="early-detection-notice">&#9888; Menuju Berat</span>
                    @endif
                </td>
                <td>{{ ucfirst($patient->wilayah_status) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="10" style="text-align: center; padding: 12px;">Tidak ada data pasien yang cocok dengan filter ini.</td>
            </tr>
        @endforelse
    </tbody>
</table>

@if ($hasEarlyDetectionPatients)
    <div class="footnote">
        <span class="footnote-icon">&#9888; Menuju Berat</span> &mdash; merupakan informasi hasil deteksi otomatis
        <i>Smart Early Detection</i> Sistem PRODULI. Tanda ini menunjukkan pasien dengan klasifikasi risiko Sedang
        yang nilai hasil pemeriksaan laboratoriumnya mendekati ambang batas kategori Berat, dan/atau menunjukkan
        kecenderungan memburuk pada beberapa hasil pemeriksaan terakhir. Kondisi ini memerlukan perhatian dan
        tindak lanjut yang lebih intensif untuk mencegah perburukan kondisi pasien lebih lanjut.
    </div>
@endif

<div class="footer">Dokumen ini dihasilkan otomatis oleh sistem PRODULI &mdash; bukan dokumen berstempel resmi.</div>

</body>
</html>
