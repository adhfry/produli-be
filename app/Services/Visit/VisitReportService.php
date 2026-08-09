<?php

namespace App\Services\Visit;

use App\DTO\VisitValidationContext;
use App\Jobs\SyncFieldUpdateToSilakesJob;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Models\VisitReportAttendee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Simpan laporan kunjungan kader — SELALU berhasil secara lokal meski SiLAKES down/lambat
 * (docs/planning/02 §2c): validasi 7-layer dulu (VisitValidationService), push foto (sudah
 * di-watermark) ke S3/MinIO (docs/planning/02 §5 — Laravel yang pegang S3, bukan Nuxt), simpan
 * lokal + update assignment/geo pasien dalam 1 transaksi, baru dispatch job TERPISAH
 * (SyncFieldUpdateToSilakesJob, dengan retry) untuk push balik geo ke SiLAKES — job itu di
 * luar alur sinkron ini, kegagalan/timeout di sana tidak pernah menggagalkan submit ini.
 *
 * Push S3 SENGAJA sinkron & di LUAR transaksi DB (bukan dijadikan queue job seperti
 * SyncFieldUpdateToSilakesJob): foto adalah bukti inti kunjungan (bukan best-effort seperti
 * update balik ke SiLAKES), jadi kalau S3 gagal, submit() ini HARUS ikut gagal (kader retry
 * submit) — bukan disimpan sebagai laporan "sukses" tanpa foto. Dilakukan SEBELUM transaksi DB
 * dibuka supaya koneksi DB tidak nganggur lama menunggu I/O upload yang lambat.
 */
class VisitReportService
{
    public function __construct(private readonly VisitValidationService $validationService) {}

    /**
     * @param  array<string, mixed>  $patientFieldUpdates  Usulan pelengkapan/koreksi data pasien
     *                                                      (kontak/alamat/identitas, docs/planning/01 §9)
     *                                                      yang digali kader saat kunjungan -- SEMUA opsional,
     *                                                      hanya key yang benar-benar diisi klien.
     * @param  array<string, mixed>  $pemeriksaan  Pemeriksaan saat kunjungan (docs/planning/02 §3:
     *                                              gda/gdp/gd2jpp/uric_acid/cholesterol/systolic/diastolic/
     *                                              keluhan/tindakan) -- SEMUA opsional, BUKAN bagian dari
     *                                              7-layer VisitValidationService, disimpan apa adanya
     *                                              sebagai kolom visit_reports (bukan diusulkan ke SiLAKES).
     * @param  ?array<int, int>  $attendeeKaderIds  Kunjungan berombongan (docs/planning/02 §16) --
     *                                               kehadiran AKTUAL. null = klien TIDAK mengirim field
     *                                               ini sama sekali -> pre-fill dari
     *                                               assignment->companions (rencana). Array (termasuk
     *                                               kosong) = klien eksplisit menentukan, pakai apa adanya.
     */
    public function submit(
        VisitAssignment $assignment,
        VisitValidationContext $context,
        string $kondisi,
        ?string $catatan = null,
        bool $confirmedPatientLocation = false,
        array $patientFieldUpdates = [],
        array $pemeriksaan = [],
        ?array $attendeeKaderIds = null,
    ): VisitReport {
        $patientFieldUpdates = array_filter($patientFieldUpdates, fn ($value) => $value !== null);
        if (! in_array($assignment->status, ['pending', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'assignment' => ["Assignment ini sudah berstatus {$assignment->status}, tidak bisa disubmit laporan lagi."],
            ]);
        }

        $summary = $this->validationService->validate($context);

        if (! $summary->passed) {
            throw ValidationException::withMessages([
                'validation' => [$summary->firstFailure()?->message ?? 'Validasi kunjungan gagal.'],
            ]);
        }

        $localPhotoPath = $summary->metadata['watermarked_photo_path'] ?? $context->photoPath;
        $photoPath = $this->storePhoto($localPhotoPath);

        if (isset($summary->metadata['watermarked_photo_path'])) {
            // File watermark cuma temp output WatermarkGenerator, sudah tidak dibutuhkan lagi
            // setelah berhasil ter-upload -- beda dari $context->photoPath asli (upload PHP,
            // bukan tanggung jawab kita untuk hapus).
            @unlink($summary->metadata['watermarked_photo_path']);
        }

        $report = DB::transaction(function () use ($assignment, $context, $kondisi, $catatan, $confirmedPatientLocation, $patientFieldUpdates, $pemeriksaan, $attendeeKaderIds, $summary, $photoPath) {
            $patient = $assignment->patient;

            $report = VisitReport::create([
                'assignment_id' => $assignment->id,
                'gps_lat' => $context->latitude,
                'gps_lng' => $context->longitude,
                'photo_path' => $photoPath,
                'exif_meta' => $this->extractExifMeta($summary->metadata),
                'face_detected' => $summary->metadata['face_detected'] ?? null,
                'kondisi' => $kondisi,
                'catatan' => $catatan,
                'geo_status' => $confirmedPatientLocation ? 'verified' : $patient->geo_status,
                'geo_source' => $confirmedPatientLocation ? 'kader_verified' : $patient->geo_source,
                'latitude' => $confirmedPatientLocation ? $context->latitude : null,
                'longitude' => $confirmedPatientLocation ? $context->longitude : null,
                'sync_status' => 'pending',
                'patient_field_updates' => $patientFieldUpdates !== [] ? $patientFieldUpdates : null,
                ...$pemeriksaan,
            ]);

            $this->recordAttendees($report, $assignment, $attendeeKaderIds);

            if ($confirmedPatientLocation) {
                $patient->update([
                    'geo_status' => 'verified',
                    'geo_source' => 'kader_verified',
                    'latitude' => $context->latitude,
                    'longitude' => $context->longitude,
                    'geo_verified_by' => $assignment->kader->user_id,
                    'geo_verified_at' => now(),
                ]);
            }

            $assignment->update(['status' => 'completed']);

            return $report;
        });

        if ($confirmedPatientLocation || $patientFieldUpdates !== []) {
            // Dispatch SETELAH transaksi commit — kegagalan/timeout panggilan SiLAKES di job ini
            // TIDAK PERNAH menggagalkan submit laporan kader (sudah tersimpan di atas). Tidak
            // dispatch kalau tidak ada geo baru yang dikonfirmasi MAUPUN usulan data pasien lain
            // — tidak ada gunanya push kosong.
            SyncFieldUpdateToSilakesJob::dispatch($report->id)->afterCommit();
        }

        return $report;
    }

    /**
     * Kunjungan berombongan (docs/planning/02 §16) -- pre-fill dari assignment->companions
     * (RENCANA saat assignment dibuat) kalau klien tidak kirim attendee_kader_ids sama sekali;
     * kalau klien kirim eksplisit (termasuk array kosong -- ada yang batal ikut / tambahan tak
     * terencana), pakai itu apa adanya. kader_id PRIMER (pengirim laporan) SENGAJA tidak ikut
     * dicatat di sini -- itu sudah otomatis dari visit_reports.assignment_id, bukan "attendee"
     * terpisah.
     */
    private function recordAttendees(VisitReport $report, VisitAssignment $assignment, ?array $attendeeKaderIds): void
    {
        $kaderIds = $attendeeKaderIds ?? $assignment->companions()->pluck('kader_id')->all();
        // kader_id primer di-filter defensif -- kalaupun klien ikut mengirimnya, tidak boleh
        // dobel-tercatat (sudah otomatis dari assignment_id).
        $kaderIds = array_diff(array_unique($kaderIds), [$assignment->kader_id]);

        foreach ($kaderIds as $kaderId) {
            VisitReportAttendee::create([
                'visit_report_id' => $report->id,
                'kader_id' => $kaderId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function extractExifMeta(array $metadata): ?array
    {
        $keys = ['exif_present', 'exif_taken_at', 'exif_gps', 'exif_gps_distance_meters'];
        $exif = array_intersect_key($metadata, array_flip($keys));

        return $exif !== [] ? $exif : null;
    }

    /**
     * Push ke S3/MinIO (docs/planning/02 §5) -- kembalikan KEY di disk tsb (bukan URL), disimpan
     * sebagai visit_reports.photo_path. Disk 's3' dikonfigurasi 'throw' => false
     * (config/filesystems.php default Laravel) -- put() TIDAK melempar exception saat gagal,
     * cuma mengembalikan false, jadi HARUS dicek eksplisit di sini. Kalau tidak, kegagalan upload
     * (mis. kredensial S3 belum diisi) akan lolos diam-diam dan photo_path tersimpan padahal
     * filenya tidak pernah benar-benar ada di storage -- bertentangan dengan catatan class-level
     * soal kenapa ini bukan best-effort.
     */
    private function storePhoto(string $localPath): string
    {
        $disk = (string) config('kopipu.storage.visit_photos_disk', 's3');
        $extension = pathinfo($localPath, PATHINFO_EXTENSION) ?: 'jpg';
        // Prefix 'pasien/' -- taksonomi kategori bucket MinIO (docs/planning/02 §5: pasien/,
        // kader/, hasil-lab/, dst, dipakai kalau ops browse bucket manual), digabung dengan
        // partisi tanggal 'visit-photos/YYYY/MM/DD/' (memudahkan audit/lifecycle policy) --
        // bukan salah satu saja, keduanya.
        $key = 'pasien/visit-photos/'.now()->format('Y/m/d').'/'.Str::uuid().'.'.$extension;

        $success = Storage::disk($disk)->put($key, file_get_contents($localPath));

        if (! $success) {
            throw new RuntimeException("Gagal upload foto kunjungan ke disk '{$disk}'.");
        }

        return $key;
    }
}
