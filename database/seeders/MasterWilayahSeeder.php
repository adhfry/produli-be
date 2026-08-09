<?php

namespace Database\Seeders;

use App\Models\Desa;
use App\Models\Kabupaten;
use App\Models\Kecamatan;
use App\Services\Silakes\SilakesApiClient;
use App\Support\WilayahTextNormalizer;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Tarik kabupaten Sumenep + kecamatan + desa dari
 * GET /api/v1/integration/master-wilayah (docs/planning/04).
 * Idempotent: re-run aman, di-upsert lewat kode_kemendagri.
 */
class MasterWilayahSeeder extends Seeder
{
    private const PROVINSI_TARGET = 'Jawa Timur';

    private const KABUPATEN_TARGET = 'Sumenep';

    public function run(SilakesApiClient $client): void
    {
        $provinsi = $this->findByName($client->masterWilayah('province'), self::PROVINSI_TARGET);

        if (! $provinsi) {
            throw new RuntimeException('Provinsi "'.self::PROVINSI_TARGET.'" tidak ditemukan di response master-wilayah SiLAKES.');
        }

        $regency = $this->findByName(
            $client->masterWilayah('regency', ['province_code' => $provinsi['code']]),
            self::KABUPATEN_TARGET,
        );

        if (! $regency) {
            throw new RuntimeException('Kabupaten "'.self::KABUPATEN_TARGET.'" tidak ditemukan di bawah provinsi '.$provinsi['name'].'.');
        }

        $kabupaten = Kabupaten::updateOrCreate(
            ['kode_kemendagri' => $regency['code']],
            ['nama' => $regency['name']],
        );

        $districts = $client->masterWilayah('district', ['regency_code' => $regency['code']]);

        $this->command?->info(sprintf('Ditemukan %d kecamatan di %s.', count($districts), $regency['name']));

        $totalDesa = 0;

        foreach ($districts as $district) {
            $kecamatan = Kecamatan::updateOrCreate(
                ['kode_kemendagri' => $district['code']],
                [
                    'kabupaten_id' => $kabupaten->id,
                    'nama' => $district['name'],
                    'latitude' => $district['latitude'] ?? null,
                    'longitude' => $district['longitude'] ?? null,
                ],
            );

            $villages = $client->masterWilayah('village', ['district_code' => $district['code']]);

            foreach ($villages as $village) {
                Desa::updateOrCreate(
                    ['kode_kemendagri' => $village['code']],
                    [
                        'kecamatan_id' => $kecamatan->id,
                        'nama' => $village['name'],
                        'latitude' => $village['latitude'] ?? null,
                        'longitude' => $village['longitude'] ?? null,
                    ],
                );
            }

            $totalDesa += count($villages);

            $this->command?->line(sprintf('  - %s: %d desa', $district['name'], count($villages)));
        }

        $this->command?->info(sprintf(
            'Selesai. 1 kabupaten, %d kecamatan, %d desa tersimpan/terupdate.',
            count($districts),
            $totalDesa,
        ));
    }

    /**
     * @param  array<int, array{code: string, name: string}>  $items
     * @return array{code: string, name: string}|null
     */
    private function findByName(array $items, string $name): ?array
    {
        $target = WilayahTextNormalizer::normalizeRegionName($name);

        foreach ($items as $item) {
            if (WilayahTextNormalizer::normalizeRegionName($item['name'] ?? '') === $target) {
                return $item;
            }
        }

        return null;
    }
}
