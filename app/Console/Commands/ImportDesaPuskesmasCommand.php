<?php

namespace App\Console\Commands;

use App\Models\Desa;
use App\Models\Puskesmas;
use App\Services\Wilayah\WilayahResolver;
use App\Support\WilayahTextNormalizer;
use Illuminate\Console\Command;

/**
 * Bulk-assign desa.puskesmas_id dari spreadsheet (CSV) hasil kerja Dinkes
 * (assign wilayah kerja sekali di awal — docs/planning/02 §2b).
 *
 * Kolom yang dikenali di header (case-insensitive), pilih salah satu jalur per baris:
 *   - desa_kode_kemendagri + puskesmas_kode_internal  (exact match, direkomendasikan)
 *   - kecamatan + desa + puskesmas                    (nama bebas, dinormalisasi)
 */
class ImportDesaPuskesmasCommand extends Command
{
    protected $signature = 'produli:import-desa-puskesmas
        {file : Path ke file CSV}
        {--dry-run : Tampilkan preview tanpa menyimpan perubahan}';

    protected $description = 'Bulk-import assignment desa.puskesmas_id dari spreadsheet CSV Dinkes';

    public function handle(WilayahResolver $resolver): int
    {
        $path = $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File tidak ditemukan atau tidak bisa dibaca: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('File CSV kosong atau header tidak terbaca.');
            fclose($handle);

            return self::FAILURE;
        }

        // Buang BOM UTF-8 kalau ada di kolom pertama.
        $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);
        $columns = array_map(fn ($col) => WilayahTextNormalizer::normalize((string) $col), $header);
        $columnIndex = array_flip($columns);

        $hasCodePath = isset($columnIndex['DESAKODEKEMENDAGRI']) && isset($columnIndex['PUSKESMASKODEINTERNAL']);
        $hasNamePath = isset($columnIndex['KECAMATAN']) && isset($columnIndex['DESA']) && isset($columnIndex['PUSKESMAS']);

        if (! $hasCodePath && ! $hasNamePath) {
            $this->error(
                'Header CSV tidak dikenali. Sediakan kolom "desa_kode_kemendagri" + "puskesmas_kode_internal", '.
                'atau kolom "kecamatan" + "desa" + "puskesmas".'
            );
            fclose($handle);

            return self::FAILURE;
        }

        $puskesmasByNama = Puskesmas::all()->keyBy(fn (Puskesmas $p) => WilayahTextNormalizer::normalize($p->nama));

        $rowNumber = 1; // baris 1 = header
        $updated = 0;
        $unchanged = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // baris kosong
            }

            $get = fn (string $key) => isset($columnIndex[$key], $row[$columnIndex[$key]])
                ? trim((string) $row[$columnIndex[$key]])
                : '';

            $desa = null;
            $puskesmas = null;

            $desaKode = $get('DESAKODEKEMENDAGRI');
            $puskesmasKode = $get('PUSKESMASKODEINTERNAL');

            if ($desaKode !== '') {
                $desa = Desa::where('kode_kemendagri', $desaKode)->first();
            }

            if (! $desa && $hasNamePath) {
                $desa = $this->findDesaByName($resolver, $get('KECAMATAN'), $get('DESA'));
            }

            if ($puskesmasKode !== '') {
                $puskesmas = Puskesmas::where('kode_internal', $puskesmasKode)->first();
            }

            if (! $puskesmas && $hasNamePath) {
                $namaPuskesmas = $get('PUSKESMAS');
                $puskesmas = $namaPuskesmas !== ''
                    ? $puskesmasByNama->get(WilayahTextNormalizer::normalize($namaPuskesmas))
                    : null;
            }

            if (! $desa) {
                $errors[] = "Baris {$rowNumber}: desa tidak ditemukan (".$this->describeRow($row, $columnIndex).')';

                continue;
            }

            if (! $puskesmas) {
                $errors[] = "Baris {$rowNumber}: puskesmas tidak ditemukan (".$this->describeRow($row, $columnIndex).')';

                continue;
            }

            if ($desa->puskesmas_id === $puskesmas->id) {
                $unchanged++;

                continue;
            }

            if (! $dryRun) {
                $desa->puskesmas_id = $puskesmas->id;
                $desa->save();
            }

            $updated++;
        }

        fclose($handle);

        $this->newLine();
        $this->info(sprintf(
            '%s%d desa diupdate, %d sudah sesuai (unchanged), %d baris gagal.',
            $dryRun ? '[DRY RUN] ' : '',
            $updated,
            $unchanged,
            count($errors),
        ));

        if ($errors !== []) {
            $this->newLine();
            $this->warn('Baris gagal:');
            foreach ($errors as $error) {
                $this->line('  - '.$error);
            }
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Delegasi ke WilayahResolver (matchKecamatan/matchDesa) supaya pencocokan nama kecamatan/desa
     * konsisten dengan sync — termasuk alias yang sudah tervalidasi (mis. "Giligenting" -> "Giliginting"),
     * bukan exact-match string literal.
     */
    private function findDesaByName(WilayahResolver $resolver, string $kecamatanNama, string $desaNama): ?Desa
    {
        if ($kecamatanNama === '' || $desaNama === '') {
            return null;
        }

        $kecamatan = $resolver->matchKecamatan($kecamatanNama);

        if (! $kecamatan) {
            return null;
        }

        return $resolver->matchDesa($desaNama, $kecamatan->id);
    }

    private function describeRow(array $row, array $columnIndex): string
    {
        $parts = [];

        foreach (['KECAMATAN', 'DESA', 'PUSKESMAS', 'DESAKODEKEMENDAGRI', 'PUSKESMASKODEINTERNAL'] as $key) {
            if (isset($columnIndex[$key], $row[$columnIndex[$key]]) && trim((string) $row[$columnIndex[$key]]) !== '') {
                $parts[] = $key.'='.trim((string) $row[$columnIndex[$key]]);
            }
        }

        return implode(', ', $parts);
    }
}
