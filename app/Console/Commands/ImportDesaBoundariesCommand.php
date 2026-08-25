<?php

namespace App\Console\Commands;

use App\Models\Desa;
use Illuminate\Console\Command;

/**
 * Impor geometri batas polygon desa dari GeoJSON (resources/geo/sumenep_desa.geojson, 334
 * fitur -- SUMBER ASLINYA sudah dipakai peta produli-frontend/public/sumenep_desa.geojson,
 * disalin ke sini supaya backend independen, tidak bergantung deploy frontend). Dipakai
 * WilayahResolver::resolveByCoordinates() -- resolusi desa dari titik GPS kader saat kunjungan
 * (permintaan user: "kader bawa koordinat kunjungan -> otomatis resolve desa/kecamatan pasien").
 *
 * Idempotent -- aman dijalankan ulang kapan saja (mis. kalau GeoJSON sumbernya diperbarui),
 * `updateOrCreate` semantik lewat kode_kemendagri sebagai kunci matching, BUKAN insert baru.
 */
class ImportDesaBoundariesCommand extends Command
{
    protected $signature = 'produli:import-desa-boundaries {--path= : Path GeoJSON kustom, default resources/geo/sumenep_desa.geojson}';

    protected $description = 'Impor geometri batas polygon desa dari GeoJSON (dipakai resolveByCoordinates())';

    public function handle(): int
    {
        $path = $this->option('path') ?: resource_path('geo/sumenep_desa.geojson');

        if (! file_exists($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $geojson = json_decode(file_get_contents($path), true);

        if (! is_array($geojson) || ! isset($geojson['features'])) {
            $this->error('File bukan GeoJSON FeatureCollection yang valid.');

            return self::FAILURE;
        }

        $matched = 0;
        $notFound = [];

        foreach ($geojson['features'] as $feature) {
            $kodeDesa = $feature['properties']['kode_desa'] ?? null;
            $geometry = $feature['geometry'] ?? null;

            if ($kodeDesa === null || $geometry === null) {
                continue;
            }

            // Normalisasi Polygon & MultiPolygon (299 vs 35 fitur di data nyata -- sebagian desa
            // di Kepulauan Kangean/Sapeken/Masalembu terdiri dari beberapa pulau terpisah) jadi
            // bentuk seragam: array of polygons, tiap polygon array of rings (ring PERTAMA =
            // outer boundary, ring selanjutnya = lubang -- lubang diabaikan di
            // resolveByCoordinates(), cukup akurat utk batas administratif, bukan presisi survei).
            $polygons = $geometry['type'] === 'MultiPolygon'
                ? $geometry['coordinates']
                : [$geometry['coordinates']];

            $desa = Desa::where('kode_kemendagri', $kodeDesa)->first();

            if ($desa === null) {
                $notFound[] = $kodeDesa;

                continue;
            }

            $desa->update(['boundary' => $polygons]);
            $matched++;
        }

        $this->info("Selesai: {$matched} desa diperbarui boundary-nya.");

        if ($notFound !== []) {
            $this->warn(count($notFound).' kode_desa dari GeoJSON tidak match ke tabel desa: '
                .implode(', ', array_slice($notFound, 0, 10)).(count($notFound) > 10 ? '...' : ''));
        }

        return self::SUCCESS;
    }
}
