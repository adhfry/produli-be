<?php

namespace App\Services\Wilayah;

use App\DTO\WilayahResolution;
use App\Models\Desa;
use App\Models\Kecamatan;
use App\Models\Puskesmas;
use App\Models\WilayahMapping;
use App\Support\WilayahTextNormalizer;
use Illuminate\Support\Collection;

/**
 * Resolusi teks bebas kel_desa/kecamatan (dari SiLAKES) -> desa/kecamatan baku KOPIPU,
 * plus resolvePuskesmas() untuk penurunan puskesmas dari hasil resolusi tsb.
 * Lihat docs/planning/02-arsitektur-backend-kopipu-smart.md §2a/§2b.
 */
class WilayahResolver
{
    /**
     * Placeholder/junk kel_desa MAUPUN kecamatan — bukan nama wilayah sungguhan, langsung
     * dianggap "tidak ada info" tanpa dicoba fuzzy-match (§2b). Penting: ini beda dari
     * out_of_scope — junk berarti datanya kosong/sampah, bukan pasien dari luar daerah.
     */
    private const JUNK_VALUES = ['LAINNYA', 'TIDAKADA', 'TANPAKETERANGAN'];

    /**
     * Perbaikan encoding rusak yang diketahui dari sampel data nyata (§2b),
     * diterapkan sebelum normalisasi. Key harus sudah uppercase+trim.
     */
    private const ENCODING_FIXUPS = [
        'KOTA SUM????' => 'KOTA SUMENEP',
        'KOTA SUMΕΝΕΡ' => 'KOTA SUMENEP', // huruf Yunani mirip Latin (Ε/Ν/Ρ), korupsi karakter lain
        'KOTA SUMENER' => 'KOTA SUMENEP', // typo P->R di akhir
    ];

    /**
     * Alias kecamatan yang ditemukan dari sampel data nyata — dicek setelah exact-match
     * gagal. "KOTA"/"KEC. KOTA" adalah sebutan sehari-hari warga Sumenep untuk kecamatan
     * "Kota Sumenep"; "GILIGENTENG"/"GILIGENTING" adalah typo umum untuk "Giliginting"
     * (tervalidasi dari volume nyata: 47 pasien) — tanpa alias ini akan salah jatuh ke
     * out_of_scope (§2b).
     */
    private const KECAMATAN_ALIASES = [
        'KOTA' => 'KOTASUMENEP',
        'GILIGENTENG' => 'GILIGINTING',
        'GILIGENTING' => 'GILIGINTING',
    ];

    private ?Collection $kecamatanCache = null;

    public function resolve(?string $kelDesaRaw, ?string $kecamatanRaw): WilayahResolution
    {
        $kelDesaRaw = $this->applyEncodingFixup($kelDesaRaw);
        $kecamatanRaw = $this->applyEncodingFixup($kecamatanRaw);

        // Placeholder/junk kecamatan ("-", "000", dst.) = tidak ada info, BUKAN out_of_scope.
        if ($kecamatanRaw !== null && $this->isJunk($kecamatanRaw)) {
            $kecamatanRaw = null;
        }

        $kecamatan = $this->matchKecamatan($kecamatanRaw);

        // out_of_scope: kecamatan diisi tapi tidak match salah satu kecamatan Sumenep —
        // pasien dari luar wilayah kerja, BUKAN backlog kerja admin (§2b).
        if ($kecamatanRaw !== null && trim($kecamatanRaw) !== '' && ! $kecamatan) {
            return new WilayahResolution(desaId: null, kecamatanId: null, wilayahStatus: 'out_of_scope');
        }

        if ($kelDesaRaw === null || trim($kelDesaRaw) === '') {
            // unknown: data belum ada dari SiLAKES, murni data quality — bukan kegagalan matching (§2a).
            return new WilayahResolution(desaId: null, kecamatanId: $kecamatan?->id, wilayahStatus: 'unknown');
        }

        if ($this->isJunk($kelDesaRaw)) {
            $this->rememberMapping($kelDesaRaw, $kecamatanRaw, null, 'unresolved');

            return new WilayahResolution(desaId: null, kecamatanId: $kecamatan?->id, wilayahStatus: 'unresolved');
        }

        $cached = WilayahMapping::where('kel_desa_raw', $kelDesaRaw)
            ->where('kecamatan_raw', $kecamatanRaw)
            ->first();

        if ($cached) {
            return new WilayahResolution(
                desaId: $cached->desa_id,
                kecamatanId: $cached->desa?->kecamatan_id ?? $kecamatan?->id,
                wilayahStatus: $cached->status === 'matched' ? 'resolved' : 'unresolved',
            );
        }

        $desa = $kecamatan ? $this->matchDesa($kelDesaRaw, $kecamatan->id) : null;

        $this->rememberMapping($kelDesaRaw, $kecamatanRaw, $desa?->id, $desa ? 'matched' : 'unresolved');

        return new WilayahResolution(
            desaId: $desa?->id,
            kecamatanId: $kecamatan?->id,
            wilayahStatus: $desa ? 'resolved' : 'unresolved',
        );
    }

    /**
     * @return array{puskesmas_id: ?int, method: string}
     */
    public function resolvePuskesmas(?int $desaId, ?int $kecamatanId): array
    {
        if ($desaId) {
            $puskesmasId = Desa::find($desaId)?->puskesmas_id;

            if ($puskesmasId) {
                return ['puskesmas_id' => $puskesmasId, 'method' => 'desa'];
            }
        }

        if (! $kecamatanId) {
            return ['puskesmas_id' => null, 'method' => 'unresolvable'];
        }

        // Sebagian besar kecamatan di Sumenep cuma punya 1 puskesmas —
        // kalau begitu, aman diinfer dari kecamatan saja tanpa desa (§2b).
        $candidates = Puskesmas::whereHas('desa', fn ($q) => $q->where('kecamatan_id', $kecamatanId))
            ->pluck('id');

        if ($candidates->count() === 1) {
            return ['puskesmas_id' => $candidates->first(), 'method' => 'kecamatan_fallback'];
        }

        return ['puskesmas_id' => null, 'method' => 'unresolvable'];
    }

    /**
     * Cari Kecamatan dari teks bebas — alias-aware (lihat KECAMATAN_ALIASES), dipakai bersama
     * oleh resolve() dan ImportDesaPuskesmasCommand supaya logika pencocokan tidak bercabang.
     */
    public function matchKecamatan(?string $raw): ?Kecamatan
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        // Level-scoped (bukan normalizeRegionName()): Sumenep punya kecamatan "Kota Sumenep"
        // yang namanya sendiri diawali "Kota" — membuang itu sebagai prefix administratif
        // akan salah (lihat WilayahTextNormalizer::LEVEL_PREFIXES).
        $target = WilayahTextNormalizer::normalizeAdministrativeName($raw, 'kecamatan');
        $target = self::KECAMATAN_ALIASES[$target] ?? $target;

        return $this->allKecamatan()->first(
            fn (Kecamatan $k) => WilayahTextNormalizer::normalizeAdministrativeName($k->nama, 'kecamatan') === $target
        );
    }

    /**
     * Cari Desa dalam scope 1 kecamatan dari teks bebas — dipakai bersama oleh resolve()
     * dan ImportDesaPuskesmasCommand.
     */
    public function matchDesa(string $raw, int $kecamatanId): ?Desa
    {
        $target = WilayahTextNormalizer::normalizeAdministrativeName($raw, 'desa');

        return Desa::where('kecamatan_id', $kecamatanId)->get()->first(
            fn (Desa $d) => WilayahTextNormalizer::normalizeAdministrativeName($d->nama, 'desa') === $target
        );
    }

    private function allKecamatan(): Collection
    {
        return $this->kecamatanCache ??= Kecamatan::all();
    }

    private function isJunk(string $raw): bool
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || $trimmed === '-') {
            return true;
        }

        $normalized = WilayahTextNormalizer::normalize($raw);

        if ($normalized === '' || ctype_digit($normalized)) {
            return true;
        }

        return in_array($normalized, self::JUNK_VALUES, true);
    }

    private function applyEncodingFixup(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return self::ENCODING_FIXUPS[mb_strtoupper(trim($raw))] ?? $raw;
    }

    private function rememberMapping(string $kelDesaRaw, ?string $kecamatanRaw, ?int $desaId, string $status): void
    {
        WilayahMapping::updateOrCreate(
            ['kel_desa_raw' => $kelDesaRaw, 'kecamatan_raw' => $kecamatanRaw],
            [
                'desa_id' => $desaId,
                'status' => $status,
                'matched_at' => $status === 'matched' ? now() : null,
            ],
        );
    }
}
