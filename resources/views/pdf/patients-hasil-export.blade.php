<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    {{-- Sama persis patients-export.blade.php (font Carlito khusus kop) -- lihat catatan di
    file itu soal font TTF yang belum ada di repo. --}}
    @font-face { font-family: 'Carlito'; font-weight: normal; font-style: normal; src: url('{{ resource_path('fonts/carlito/Carlito-Regular.ttf') }}'); }
    @font-face { font-family: 'Carlito'; font-weight: bold; font-style: normal; src: url('{{ resource_path('fonts/carlito/Carlito-Bold.ttf') }}'); }
    @font-face { font-family: 'Carlito'; font-weight: normal; font-style: italic; src: url('{{ resource_path('fonts/carlito/Carlito-Italic.ttf') }}'); }
    @font-face { font-family: 'Carlito'; font-weight: bold; font-style: italic; src: url('{{ resource_path('fonts/carlito/Carlito-BoldItalic.ttf') }}'); }

    @page { margin: 18px 20px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #1e293b; }

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

    {{-- Tabel kolom dinamis bisa jauh lebih lebar dari kertas -- word-wrap supaya isi sel
    panjang (nama pasien, dst) tidak memaksa kolom parameter jadi lebih sempit dari perlu. --}}
    table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.data th {
        background: #0f766e; color: #ffffff; font-size: 7px; text-transform: uppercase;
        padding: 4px 3px; text-align: left; border: 1px solid #0f766e; word-wrap: break-word;
    }
    table.data td { padding: 3px; border: 1px solid #e2e8f0; font-size: 7.5px; word-wrap: break-word; }
    table.data tr:nth-child(even) td { background: #f8fafc; }

    .footer { margin-top: 10px; font-size: 7.5px; color: #94a3b8; text-align: right; }
</style>
</head>
<body>

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
            <div class="report-title">Laporan Hasil Pemeriksaan Pasien Prolanis</div>
            @if ($filterSummary)
                <div class="report-scope">{{ $filterSummary }}</div>
            @endif
            <div class="report-meta">
                Diunduh oleh {{ $generatedBy->name }} &middot; {{ $generatedAt->translatedFormat('d F Y, H:i') }} WIB<br>
                Total {{ number_format($totalCount, 0, ',', '.') }} pasien &middot; {{ count($parameters) }} parameter pemeriksaan
            </div>
        </td>
    </tr>
</table>

@php
    // Lebar kolom "No"/"Nama"/"NIK"/"Desa-Kecamatan" tetap proporsional lebih besar, sisanya
    // dibagi rata ke seluruh kolom parameter dinamis -- supaya tabel dgn banyak parameter tidak
    // membuat kolom identitas pasien jadi terlalu sempit utk terbaca.
    $fixedWidthPercent = 34;
    $paramWidthPercent = count($parameters) > 0 ? (100 - $fixedWidthPercent) / count($parameters) : 0;
@endphp

<table class="data">
    <thead>
        <tr>
            <th style="width: 3%;">No</th>
            <th style="width: 14%;">Nama</th>
            <th style="width: 8%;">NIK</th>
            <th style="width: 9%;">Desa / Kecamatan</th>
            @foreach ($parameters as $parameter)
                <th style="width: {{ $paramWidthPercent }}%;">{{ $parameter }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $index => $row)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $row['nama'] }}</td>
                <td>{{ $row['nik'] }}</td>
                <td>{{ $row['wilayah'] }}</td>
                @foreach ($parameters as $parameter)
                    <td>{{ $row['values'][$parameter] ?? '-' }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ 4 + count($parameters) }}" style="text-align: center; padding: 12px;">Tidak ada data pasien yang cocok dengan filter ini.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="footer">Dokumen ini dihasilkan otomatis oleh sistem PRODULI &mdash; bukan dokumen berstempel resmi.</div>

</body>
</html>
