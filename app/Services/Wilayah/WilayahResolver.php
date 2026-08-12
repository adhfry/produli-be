<?php

namespace App\Services\Wilayah;

use App\DTO\WilayahResolution;
use App\Models\Desa;
use App\Models\Kecamatan;
use App\Models\LabResultCache;
use App\Models\Puskesmas;
use App\Models\WilayahMapping;
use App\Support\WilayahTextNormalizer;
use Illuminate\Support\Collection;

/**
 * Resolusi teks bebas kel_desa/kecamatan (dari SiLAKES) -> desa/kecamatan baku PRODULI,
 * plus resolvePuskesmas() untuk penurunan puskesmas dari hasil resolusi tsb.
 * Lihat docs/planning/02-arsitektur-backend-produli.md §2a/§2b.
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

    private ?Collection $puskesmasCache = null;

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
     * Token pertama pengirim yang menandakan rujukan PERORANGAN (dokter/bidan), bukan institusi
     * puskesmas -- data nyata penuh nilai seperti "dr. Laos Susantina, SE", "Bidan Ika",
     * "drg. Yenniy Ismullah" (revisi Bu Kadis, Fase 5). Dicek SEBELUM mencoba cocokkan ke
     * puskesmas -- rujukan begini TIDAK PERNAH boleh dipaksa jadi salah satu dari 31 puskesmas.
     */
    private const INDIVIDUAL_REFERRAL_PREFIXES = ['DR', 'DRG', 'DOKTER', 'BIDAN', 'PROF'];

    /**
     * Token yang menandakan awal nama institusi puskesmas -- dicocokkan TYPO-TOLERANT (lihat
     * looksLikePuskesmasPrefixToken()) karena data nyata penuh salah ketik pada kata "Puskesmas"
     * itu sendiri: "Puskemas"/"Puskesams"/"Pusekesmas"/"Piskesmas", belum lagi singkatan "PKM".
     */
    private const PUSKESMAS_PREFIX_CANDIDATES = ['PUSKESMAS', 'PKM', 'UPTD', 'UPT'];

    /**
     * $externalPatientId (opsional) -- dipakai untuk fallback TERAKHIR lewat
     * matchPuskesmasByPengirim()/isIndividualReferral() kalau desa/kecamatan_fallback di atas
     * SUDAH gagal (revisi Bu Kadis, Fase 5). Tanpa argumen ini, perilaku persis sama seperti
     * sebelumnya. `pengirim_raw` SELALU disertakan di hasil (apa pun method-nya) kalau
     * $externalPatientId diisi -- dipakai frontend menampilkan "Rujukan: dr. X" untuk kasus
     * individual, atau teks asli untuk audit kasus unresolvable.
     *
     * @return array{puskesmas_id: ?int, method: string, pengirim_raw: ?string}
     */
    public function resolvePuskesmas(?int $desaId, ?int $kecamatanId, ?int $externalPatientId = null): array
    {
        $pengirimRaw = $externalPatientId !== null ? $this->latestPengirim($externalPatientId) : null;

        if ($desaId) {
            $puskesmasId = Desa::find($desaId)?->puskesmas_id;

            if ($puskesmasId) {
                return ['puskesmas_id' => $puskesmasId, 'method' => 'desa', 'pengirim_raw' => $pengirimRaw];
            }
        }

        if ($kecamatanId) {
            // Sebagian besar kecamatan di Sumenep cuma punya 1 puskesmas —
            // kalau begitu, aman diinfer dari kecamatan saja tanpa desa (§2b).
            // Pakai puskesmas.kecamatan_id langsung (bukan whereHas('desa', ...)) — link itu
            // butuh desa.puskesmas_id sudah ter-assign duluan (baru dilakukan lewat CSV manual
            // utk 4 kecamatan multi-puskesmas), sementara puskesmas.kecamatan_id sudah terisi
            // penuh dari seeder utk semua 31 puskesmas, tidak bergantung assignment desa apa pun.
            $candidates = Puskesmas::where('kecamatan_id', $kecamatanId)->pluck('id');

            if ($candidates->count() === 1) {
                return ['puskesmas_id' => $candidates->first(), 'method' => 'kecamatan_fallback', 'pengirim_raw' => $pengirimRaw];
            }
        }

        // Revisi Bu Kadis (Fase 5): fallback TERAKHIR sebelum menyerah -- cocokkan kolom bebas
        // `pengirim` (surat_hasil_labs SiLAKES, docs/planning/04) ke puskesmas.nama. Ini SINYAL
        // TAMBAHAN, bukan pengganti desa/kecamatan_fallback -- baru dicoba setelah keduanya
        // gagal.
        if ($pengirimRaw !== null) {
            if ($this->isIndividualReferral($pengirimRaw)) {
                return ['puskesmas_id' => null, 'method' => 'pengirim_individual', 'pengirim_raw' => $pengirimRaw];
            }

            $puskesmas = $this->matchPuskesmasByPengirim($pengirimRaw);

            if ($puskesmas !== null) {
                return ['puskesmas_id' => $puskesmas->id, 'method' => 'pengirim_matched', 'pengirim_raw' => $pengirimRaw];
            }
        }

        return ['puskesmas_id' => null, 'method' => 'unresolvable', 'pengirim_raw' => $pengirimRaw];
    }

    private function latestPengirim(int $externalPatientId): ?string
    {
        $raw = LabResultCache::where('patient_id', $externalPatientId)
            ->whereNotNull('pengirim')
            ->orderByDesc('tanggal_periksa')
            ->value('pengirim');

        return $raw !== null && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * Rujukan perorangan (dokter/bidan) -- token pertama dicocokkan case-insensitive terhadap
     * INDIVIDUAL_REFERRAL_PREFIXES, bukan puskesmas sama sekali (lihat docblock const).
     */
    private function isIndividualReferral(string $raw): bool
    {
        $firstToken = preg_split('/\s+/', trim($raw), 2)[0] ?? '';
        $firstToken = mb_strtoupper(preg_replace('/[^A-Za-z]/', '', $firstToken));

        return in_array($firstToken, self::INDIVIDUAL_REFERRAL_PREFIXES, true);
    }

    /**
     * Cocokkan `pengirim` (teks bebas, sudah dipastikan BUKAN rujukan perorangan oleh
     * isIndividualReferral()) ke puskesmas.nama -- data nyata sangat kotor: variasi kapitalisasi/
     * spasi/tanda hubung/apostrof (semua sudah ditangani normalize()), DITAMBAH salah ketik pada
     * kata "Puskesmas" itu sendiri ("Puskemas"/"Puskesams"/"Pusekesmas"/"Piskesmas"/singkatan
     * "PKM") MAUPUN pada nama puskesmas-nya ("Prgaan" utk "Pragaan", "NUNGGUNONG" utk
     * "Nonggunong") -- revisi Bu Kadis, Fase 5.
     *
     * Strategi 2 lapis, keduanya lewat skor "seberapa jauh" (0=exact, 1=salah satu prefix dari
     * yang lain, N=jarak Levenshtein) supaya bisa dibandingkan APPLES-TO-APPLES lintas 31
     * kandidat dan dipilih SATU yang paling meyakinkan:
     * 1. Exact match (skor 0) setelah prefix institusi (typo-tolerant) dibuang dari depan.
     * 2. Salah satu nama adalah PREFIX dari yang lain (skor 1) -- menangkap kasus pengirim
     *    menyebut sebagian nama saja (mis. "Puskesmas Moncek" padahal resminya "Moncek Tengah").
     * 3. Jarak Levenshtein <= ambang toleran (skor = jarak) -- menangkap salah ketik ringan.
     *
     * TIDAK PERNAH menebak kalau ada 2+ kandidat dengan skor sama/berdekatan (mis. "Batu" bisa
     * cocok prefix "Batuan" MAUPUN "Batuputih" sekaligus) -- ambigu dibiarkan unresolvable.
     */
    private function matchPuskesmasByPengirim(string $pengirimRaw): ?Puskesmas
    {
        $tokens = preg_split('/\s+/', mb_strtoupper(trim($pengirimRaw)), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_filter(
            array_map(fn (string $t) => preg_replace('/[^A-Z0-9]/', '', $t), $tokens),
            fn (string $t) => $t !== ''
        ));

        while ($tokens !== [] && $this->looksLikePuskesmasPrefixToken($tokens[0])) {
            array_shift($tokens);
        }

        if ($tokens === []) {
            return null;
        }

        $target = implode('', $tokens);

        $best = null;
        $bestScore = null;
        $secondBestScore = null;

        foreach ($this->allPuskesmas() as $puskesmas) {
            $candidate = WilayahTextNormalizer::normalizeAdministrativeName($puskesmas->nama, 'puskesmas');
            $score = $this->matchScore($target, $candidate);

            if ($score === null) {
                continue;
            }

            if ($bestScore === null || $score < $bestScore) {
                $secondBestScore = $bestScore;
                $bestScore = $score;
                $best = $puskesmas;
            } elseif ($secondBestScore === null || $score < $secondBestScore) {
                $secondBestScore = $score;
            }
        }

        if ($best === null) {
            return null;
        }

        // Exact match (skor 0) SELALU diterima -- kandidat lain yang kebetulan "dekat" tidak
        // relevan kalau yang ini sudah cocok persis. Skor > 0 (prefix/typo) dianggap ambigu
        // HANYA kalau skor kedua-terbaik SAMA PERSIS (mis. "Masalembu" prefix dari 2 puskesmas
        // sekaligus, skor 1 keduanya) -- BUKAN sekadar "berdekatan". Margin longgar sempat
        // menolak kasus jelas seperti "Puskesmas Mandng" (typo 1 huruf dari "Manding", skor 1)
        // gara-gara "Ganding" kebetulan skor 2 -- 2 nama tempat pendek yang beda kandidatnya
        // sendiri sudah cukup jauh, bukan ambiguitas sungguhan.
        if ($bestScore > 0 && $secondBestScore !== null && $secondBestScore === $bestScore) {
            return null;
        }

        return $best;
    }

    private function looksLikePuskesmasPrefixToken(string $token): bool
    {
        foreach (self::PUSKESMAS_PREFIX_CANDIDATES as $prefix) {
            if (levenshtein($token, $prefix) <= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ?int null kalau tidak cukup dekat sama sekali, selain itu skor 0 (exact) / 1
     *              (salah satu prefix dari yang lain) / jarak Levenshtein.
     */
    private function matchScore(string $target, string $candidate): ?int
    {
        if ($target === $candidate) {
            return 0;
        }

        // Minimal 4 karakter -- di bawah itu prefix-match terlalu longgar (banyak nama singkat
        // kebetulan berawalan sama).
        if (strlen($target) >= 4 && strlen($candidate) >= 4
            && (str_starts_with($candidate, $target) || str_starts_with($target, $candidate))) {
            return 1;
        }

        $distance = levenshtein($target, $candidate);
        $threshold = max(2, (int) floor(strlen($candidate) * 0.25));

        return $distance <= $threshold ? $distance : null;
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

    private function allPuskesmas(): Collection
    {
        return $this->puskesmasCache ??= Puskesmas::all();
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
