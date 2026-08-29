<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: 'Carlito'; font-weight: normal; font-style: normal; src: url('{{ resource_path('fonts/carlito/Carlito-Regular.ttf') }}'); }
    @font-face { font-family: 'Carlito'; font-weight: bold; font-style: normal; src: url('{{ resource_path('fonts/carlito/Carlito-Bold.ttf') }}'); }

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
    .badge-baru { background: #fef3c7; color: #b45309; }

    .footer { margin-top: 10px; font-size: 7.5px; color: #94a3b8; text-align: right; }
    .signature { margin-top: 28px; width: 100%; }
    .signature td { width: 50%; text-align: center; font-size: 9px; vertical-align: top; }
    .signature .space { height: 44px; }
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
            <div class="report-title">Daftar Antrian Pengiriman Sampel Prolanis</div>
            <div class="report-scope">{{ $pengirimanSampel->puskesmas->nama }}</div>
            <div class="report-meta">
                Dicetak oleh {{ $generatedBy->name }} &middot; {{ $generatedAt->translatedFormat('d F Y, H:i') }} WIB<br>
                Total {{ number_format($pengirimanSampel->pasien->count(), 0, ',', '.') }} pasien
            </div>
        </td>
    </tr>
</table>

<table class="data">
    <thead>
        <tr>
            <th style="width: 6%;">No</th>
            <th style="width: 34%;">Nama Pasien</th>
            <th style="width: 15%;">NIK</th>
            <th style="width: 15%;">Jenis Prolanis</th>
            <th style="width: 30%;">Keterangan</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($pengirimanSampel->pasien as $index => $pasien)
            <tr>
                <td>{{ $pasien->urutan }}</td>
                <td>{{ $pasien->nama_snapshot }}</td>
                <td>{{ $pasien->isPasienBaru() ? \App\Support\NikDisplay::resolve($pasien->data_pasien_baru_nik) : '-' }}</td>
                <td>{{ $pasien->jenis_prolanis_snapshot ?? '-' }}</td>
                <td>
                    @if ($pasien->isPasienBaru())
                        <span class="badge badge-baru">Pasien Baru</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" style="text-align: center; padding: 12px;">Antrian ini belum berisi pasien.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="signature">
    <tr>
        <td>
            Mengetahui,<br>
            {{ $pengirimanSampel->puskesmas->nama }}
            <div class="space"></div>
            ( .............................. )
        </td>
        <td>
            Pengantar Sampel,
            <div class="space"></div>
            ( {{ $pengirimanSampel->pengantarSampel?->user?->name ?? '..............................' }} )
        </td>
    </tr>
</table>

<div class="footer">Dokumen ini dihasilkan otomatis oleh sistem PRODULI &mdash; bukan dokumen berstempel resmi.</div>

</body>
</html>
