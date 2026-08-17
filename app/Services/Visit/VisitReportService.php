<?php

namespace App\Services\Visit;

use App\DTO\VisitValidationContext;
use App\Jobs\SyncFieldUpdateToSilakesJob;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Models\VisitReportAttendee;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

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
    public function __construct(
        private readonly VisitValidationService $validationService,
        private readonly NotifyService $notifyService,
    ) {}

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

        // Idempotensi retry offline (docs/planning/15, temuan audit -- OfflineQueueHandler
        // Layer 7 sudah mewajibkan client_submission_id tapi sebelumnya TIDAK PERNAH benar-benar
        // dicek ke DB). Submission yang SAMA (retry dari antrean IndexedDB kader di sinyal lemah)
        // dikembalikan apa adanya, BUKAN diproses ulang -- cek "murah" ini duluan supaya retry
        // yang jelas-jelas sudah sukses tidak perlu lewat 7-layer validation + upload S3 yang
        // mahal lagi. unique constraint di migration jadi jaring pengaman terakhir untuk balapan
        // sungguhan (lihat catch QueryException di bawah).
        if ($context->clientSubmissionId) {
            $existing = VisitReport::where('client_submission_id', $context->clientSubmissionId)->first();
            if ($existing) {
                return $existing;
            }
        }

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

        try {
            $report = DB::transaction(function () use ($assignment, $context, $kondisi, $catatan, $confirmedPatientLocation, $patientFieldUpdates, $pemeriksaan, $attendeeKaderIds, $summary, $photoPath) {
                // Kunci baris assignment SELAMA transaksi (docs/planning/15, temuan audit) --
                // tanpa ini, dua request submit BERSAMAAN untuk assignment yang sama bisa lolos
                // cek status di atas SEBELUM salah satu sempat commit (dua-duanya baca
                // 'pending'), menghasilkan dua VisitReport untuk satu kunjungan. Re-cek status
                // di sini pakai baris yang TERKUNCI, bukan objek $assignment lama yang mungkin
                // sudah basi.
                $lockedAssignment = VisitAssignment::whereKey($assignment->id)->lockForUpdate()->first();
                if (! in_array($lockedAssignment->status, ['pending', 'in_progress'], true)) {
                    throw ValidationException::withMessages([
                        'assignment' => ["Assignment ini sudah berstatus {$lockedAssignment->status}, tidak bisa disubmit laporan lagi."],
                    ]);
                }

                $patient = $assignment->patient;

                $report = VisitReport::create([
                    'assignment_id' => $assignment->id,
                    'client_submission_id' => $context->clientSubmissionId,
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
                    // Otomatis menunggu konfirmasi admin_puskesmas/pj_prolanis begitu kader/nakes
                    // memilih 'dirujuk_puskesmas' di antara tindakan (bisa lebih dari satu
                    // tindakan sekaligus, docs plan Fase 2) -- dikonfirmasi/dibatalkan di
                    // /dashboard/rujukan (Fase 3, di luar cakupan perubahan ini).
                    'rujukan_status' => in_array('dirujuk_puskesmas', $pemeriksaan['tindakan'] ?? [], true)
                        ? 'menunggu_konfirmasi'
                        : null,
                ]);

                $this->recordAttendees($report, $assignment, $attendeeKaderIds);

                if ($confirmedPatientLocation) {
                    $patient->update([
                        'geo_status' => 'verified',
                        'geo_source' => 'kader_verified',
                        'latitude' => $context->latitude,
                        'longitude' => $context->longitude,
                        'geo_verified_by' => $assignment->kader?->user_id ?? $assignment->tenagaKesehatan?->user_id,
                        'geo_verified_at' => now(),
                    ]);
                }

                $lockedAssignment->update(['status' => 'completed']);

                return $report;
            });
        } catch (QueryException $e) {
            // Backstop unique constraint (visit_reports.client_submission_id) -- kalau dua
            // request BENAR-BENAR lolos cek "existing" di atas nyaris bersamaan (jendela balapan
            // super sempit antara cek dan insert), salah satu gagal di sini. Perlakukan sebagai
            // SUKSES (kembalikan yang sudah tersimpan oleh request yang menang), bukan error --
            // tujuan submit ini (laporan tersimpan) sudah tercapai.
            if ($context->clientSubmissionId && $this->isUniqueConstraintViolation($e)) {
                $existing = VisitReport::where('client_submission_id', $context->clientSubmissionId)->first();
                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }

        if ($confirmedPatientLocation || $patientFieldUpdates !== []) {
            // Dispatch SETELAH transaksi commit — kegagalan/timeout panggilan SiLAKES di job ini
            // TIDAK PERNAH menggagalkan submit laporan kader (sudah tersimpan di atas). Tidak
            // dispatch kalau tidak ada geo baru yang dikonfirmasi MAUPUN usulan data pasien lain
            // — tidak ada gunanya push kosong.
            SyncFieldUpdateToSilakesJob::dispatch($report->id)->afterCommit();
        }

        $this->notifyReportSubmitted($assignment);

        if ($report->rujukan_status === 'menunggu_konfirmasi') {
            $this->notifyPasienDirujuk($assignment, $report);
        }

        return $report;
    }

    /**
     * MySQL error code 23000 = integrity constraint violation (mencakup unique index) -- dipakai
     * VisitReportService::submit() untuk membedakan "gagal karena duplikat client_submission_id"
     * (backstop race condition, ditangani sebagai sukses) dari kegagalan DB lain (harus tetap
     * dilempar ulang sebagai error sungguhan).
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }

    /**
     * Notifikasi ke admin_puskesmas + pj_prolanis di puskesmas PETUGAS PELAPOR (kader/nakes) --
     * BUKAN puskesmas_id_snapshot assignment (itu turunan puskesmas PASIEN, lihat
     * VisitAssignmentService/CareAssignmentService -- salah kalau dipakai di sini, laporan harus
     * naik ke puskesmas tempat kader/nakes yang mengirim laporan bertugas). Setiap kali laporan
     * kunjungan masuk -- sebelumnya TIDAK ADA notifikasi yang mengalir ke atas sama sekali
     * (kader/nakes -> admin/PJ). Dikirim lewat push (bel) DAN fcm (push notification browser/PWA)
     * supaya benar-benar sampai walau app sedang tidak dibuka. Notifikasi KHUSUS rujukan (channel
     * push+fcm+email, ditambah halaman /dashboard/rujukan + alarm) ditangani terpisah, bukan di sini.
     *
     * Dibungkus try/catch SENGAJA -- laporan kunjungan yang SUDAH tersimpan (transaksi di atas
     * sudah commit) tidak boleh dianggap gagal cuma karena sisi notifikasi bermasalah (mis. role
     * belum ter-seed di environment tertentu, NotifyService throw). Prinsip yang sama seperti
     * NotifyService::deliverToUser() yang menelan kegagalan per-channel -- di sini satu tingkat
     * lebih luar, membungkus resolveUserIds() yang BISA throw (beda dari deliverToUser() yang
     * sudah aman sendiri).
     */
    private function notifyReportSubmitted(VisitAssignment $assignment): void
    {
        try {
            $petugasPuskesmasId = $assignment->kader?->puskesmas_id ?? $assignment->tenagaKesehatan?->puskesmas_id;
            if ($petugasPuskesmasId === null) {
                return;
            }

            $patientName = $assignment->patient?->nama ?? 'pasien';
            $petugasName = $assignment->kader?->user?->name ?? $assignment->tenagaKesehatan?->user?->name ?? 'Petugas';

            $this->notifyService->notify(
                NotifiableTarget::rolesInPuskesmas(['admin_puskesmas', 'pj_prolanis'], $petugasPuskesmasId),
                new NotificationPayload(
                    type: 'visit_report_submitted',
                    title: 'Laporan Kunjungan Baru',
                    body: "{$petugasName} baru saja mengirim laporan kunjungan untuk {$patientName}.",
                    data: [
                        'type' => 'visit_report_submitted',
                        'severity' => 'danger',
                        'assignment_id' => $assignment->id,
                        'patient_id' => $assignment->patient_id,
                        'action_url' => "/dashboard/kunjungan?assignment_id={$assignment->id}",
                        'action_label' => 'Lihat Kunjungan',
                    ],
                ),
                ['push', 'fcm'],
            );
        } catch (Throwable $e) {
            Log::warning('VisitReportService: gagal mengirim notifikasi laporan kunjungan, laporan tetap tersimpan', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifikasi KHUSUS rujukan (docs plan Fase 2/3, beda dari notifyReportSubmitted() di atas
     * yang notifikasi biasa) -- dipicu HANYA kalau kader/nakes memilih 'dirujuk_puskesmas' di
     * antara tindakan. Dikirim 3 kanal sekaligus (push+fcm+email) karena ini butuh RESPON CEPAT
     * (pasien akan datang ke puskesmas, admin/PJ perlu tahu & konfirmasi) -- beda dari laporan
     * kunjungan biasa yang cukup 2 kanal. Halaman /dashboard/rujukan (Fase 3, belum dibangun di
     * perubahan ini) yang akan memicu alarm sfx saat polling menemukan baris baru -- notifikasi
     * ini sendiri TIDAK memicu alarm, cuma penyalur data.
     *
     * Target puskesmas & try/catch mengikuti prinsip PERSIS sama seperti notifyReportSubmitted()
     * di atas -- lihat docblock method itu.
     */
    private function notifyPasienDirujuk(VisitAssignment $assignment, VisitReport $report): void
    {
        try {
            $petugasPuskesmasId = $assignment->kader?->puskesmas_id ?? $assignment->tenagaKesehatan?->puskesmas_id;
            if ($petugasPuskesmasId === null) {
                return;
            }

            $patientName = $assignment->patient?->nama ?? 'pasien';
            $petugasName = $assignment->kader?->user?->name ?? $assignment->tenagaKesehatan?->user?->name ?? 'Petugas';

            $this->notifyService->notify(
                NotifiableTarget::rolesInPuskesmas(['admin_puskesmas', 'pj_prolanis'], $petugasPuskesmasId),
                new NotificationPayload(
                    type: 'pasien_dirujuk',
                    title: 'Pasien Dirujuk ke Puskesmas',
                    body: "{$petugasName} merujuk pasien {$patientName} ke puskesmas, menunggu konfirmasi.",
                    data: [
                        'type' => 'pasien_dirujuk',
                        'severity' => 'danger',
                        'assignment_id' => $assignment->id,
                        'visit_report_id' => $report->id,
                        'patient_id' => $assignment->patient_id,
                        'action_url' => "/dashboard/kunjungan?assignment_id={$assignment->id}",
                        'action_label' => 'Lihat Kunjungan',
                    ],
                ),
                ['push', 'fcm', 'email'],
            );
        } catch (Throwable $e) {
            Log::warning('VisitReportService: gagal mengirim notifikasi rujukan, laporan tetap tersimpan', [
                'assignment_id' => $assignment->id,
                'visit_report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
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
        $disk = (string) config('produli.storage.visit_photos_disk', 's3');
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
