<?php

namespace App\Services\Ktp;

use App\Exceptions\SilakesApiException;
use App\Models\KtpScan;
use App\Models\PatientsCache;
use App\Models\User;
use App\Services\Silakes\SilakesApiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Scan KTP pasien oleh staf puskesmas (revisi 2026-11-16).
 *
 * 1. Foto disimpan dulu di PRODULI (disk produli.storage.ktp_photos_disk) -- bila OCR gagal, foto
 *    tetap terpakai dan staf mengisi form manual.
 * 2. Foto dibaca lewat SiLAKES (POST /integration/ktp-ocr): mesin & cek silang NIK sama persis
 *    dengan registrasi SiLAKES, tanpa kunci AI di server PRODULI.
 * 3. Saat usulan benar-benar dikirim, job membaca foto ini lagi (base64Of) dan mengirimnya ke
 *    SiLAKES -- foto TIDAK ikut tersimpan di payload antrian (Redis), hanya id-nya.
 */
class KtpScanService
{
    public function __construct(private readonly SilakesApiClient $client) {}

    public function scan(UploadedFile $file, User $user): KtpScan
    {
        $disk = (string) config('produli.storage.ktp_photos_disk');
        $mime = $file->getMimeType() === 'image/png' ? 'image/png' : 'image/jpeg';
        $path = $file->storeAs(
            'ktp/'.now()->format('Y/m'),
            uniqid('ktp_', true).($mime === 'image/png' ? '.png' : '.jpg'),
            $disk,
        );

        $scan = KtpScan::create([
            'user_id' => $user->id,
            'puskesmas_id' => $user->puskesmas_id,
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'size_bytes' => (int) $file->getSize(),
        ]);

        try {
            $hasil = $this->client->ktpOcr('data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file->getRealPath())));

            $scan->update([
                'ocr_fields' => $hasil['fields'] ?? [],
                'ocr_checks' => $hasil['checks'] ?? [],
                'ocr_model' => $hasil['model'] ?? null,
            ]);
        } catch (SilakesApiException $e) {
            // 422 dari SiLAKES = foto bukan KTP / tidak terbaca: pesannya sudah ramah untuk staf.
            $scan->update(['ocr_error' => $e->isClientError()
                ? $this->pesanSilakes($e)
                : 'KTP belum bisa dibaca otomatis saat ini. Isi data secara manual, foto tetap tersimpan.']);
        } catch (Throwable $e) {
            Log::warning('Scan KTP: gagal menghubungi SiLAKES', ['ktp_scan_id' => $scan->id, 'error' => $e->getMessage()]);
            $scan->update(['ocr_error' => 'KTP belum bisa dibaca otomatis saat ini. Isi data secara manual, foto tetap tersimpan.']);
        }

        return $scan->refresh();
    }

    /** Id pasien PRODULI yang NIK-nya sama dengan hasil scan (peringatan data ganda), null bila tidak ada. */
    public function existingPatientId(KtpScan $scan): ?int
    {
        $nik = $scan->ocr_fields['nik'] ?? null;
        if (! is_string($nik) || strlen($nik) !== 16) {
            return null;
        }

        return PatientsCache::where('nik', $nik)
            ->orWhere('nik_hash', \App\Support\NikHasher::hash($nik))
            ->value('id');
    }

    /**
     * Ambil scan milik user ini untuk dipakai di usulan. Scan milik user lain atau yang sudah
     * dipakai usulan lain ditolak, supaya satu foto tidak menempel ke pasien yang salah.
     */
    public function ambilUntukDipakai(int $scanId, User $user): KtpScan
    {
        $scan = KtpScan::find($scanId);

        if (! $scan || $scan->user_id !== $user->id) {
            throw ValidationException::withMessages(['ktp_scan_id' => ['Foto KTP tidak ditemukan. Scan ulang KTP pasien.']]);
        }
        if ($scan->used_at !== null) {
            throw ValidationException::withMessages(['ktp_scan_id' => ['Foto KTP ini sudah dipakai untuk usulan lain. Scan ulang KTP pasien.']]);
        }

        return $scan;
    }

    /** Isi foto sebagai data URI base64, untuk dikirim ke SiLAKES. Null bila berkas hilang. */
    public function base64Of(?int $scanId): ?string
    {
        $scan = $scanId ? KtpScan::find($scanId) : null;
        if (! $scan || ! Storage::disk($scan->disk)->exists($scan->path)) {
            return null;
        }

        return 'data:'.$scan->mime.';base64,'.base64_encode((string) Storage::disk($scan->disk)->get($scan->path));
    }

    private function pesanSilakes(SilakesApiException $e): string
    {
        // Format pesan SilakesApiClient: "Panggilan SiLAKES <path> gagal (HTTP 422): <pesan asli>"
        $asli = preg_replace('/^.*\(HTTP \d+\):\s*/', '', $e->getMessage());

        return $asli !== '' ? $asli : 'Foto tidak terbaca sebagai KTP. Foto ulang dengan seluruh kartu masuk bingkai.';
    }
}
