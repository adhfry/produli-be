<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20px 24px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1e293b; }

    .letterhead { width: 100%; border-bottom: 2px solid #0f766e; padding-bottom: 8px; margin-bottom: 10px; }
    .letterhead td { vertical-align: middle; }
    .letterhead img { height: 42px; }
    .letterhead .org-parent { font-size: 8.5px; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.3px; }
    .letterhead .org-name { font-size: 13.5px; font-weight: bold; color: #0f172a; margin-top: 1px; }
    .letterhead .org-sub { font-size: 8.5px; color: #64748b; margin-top: 2px; }
    .letterhead .report-title { text-align: right; font-size: 13px; font-weight: bold; color: #0f766e; }
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

    .footer { margin-top: 10px; font-size: 7.5px; color: #94a3b8; text-align: right; }
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
@endphp

<table class="letterhead">
    <tr>
        <td style="width: 60px;">
            <img src="{{ \App\Support\MailBranding::logoDataUri() }}" alt="Logo PRODULI">
        </td>
        <td>
            <div class="org-parent">Dinas Kesehatan, Pengendalian Penduduk dan Keluarga Berencana (P2KB)</div>
            <div class="org-name">UPTD Laboratorium Kesehatan Daerah</div>
            <div class="org-sub">Kabupaten Sumenep &mdash; Sistem PRODULI (Pelayanan Proaktif Prolanis)</div>
        </td>
        <td>
            <div class="report-title">Laporan Data Pasien Prolanis</div>
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
            <th style="width: 8%;">No. Registrasi</th>
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
                $age = $patient->tgl_lahir ? $patient->tgl_lahir->diffInYears(now()) : null;
                $level = $patient->latestRiskClassification?->level;
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $patient->nama }}</td>
                <td>{{ $patient->no_reg ?? '-' }}</td>
                <td>{{ $age !== null ? $age.' th' : '-' }} / {{ $patient->gender ?? '-' }}</td>
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
                <td><span class="badge badge-{{ $level ?? 'null' }}">{{ $riskLabels[$level] ?? 'Belum Dihitung' }}</span></td>
                <td>{{ ucfirst($patient->wilayah_status) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="10" style="text-align: center; padding: 12px;">Tidak ada data pasien yang cocok dengan filter ini.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="footer">Dokumen ini dihasilkan otomatis oleh sistem PRODULI &mdash; bukan dokumen berstempel resmi.</div>

</body>
</html>
