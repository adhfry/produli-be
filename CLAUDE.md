# CLAUDE.md — PRODULI Backend (letakkan file ini di root repo Laravel PRODULI)

## Konteks Proyek

PRODULI adalah sistem "Active Healthcare" untuk UPTD Labkesda/Dinkes
Kabupaten Sumenep — mengubah data hasil lab (dari SiLAKES) menjadi
kunjungan rumah terarah oleh kader ke pasien risiko tinggi (Prolanis).
Stack: Laravel 11, PHP 8.3, MySQL, Redis, Horizon, Spatie Permission,
Laravel Pulse, Sanctum.

## Arsitektur (lihat `02-arsitektur-backend-produli.md`)

- Layered: Controller (tipis) → Service → Repository → Model.
- Response API wajib format `{status, message, data}` via
  `ApiResponse` helper + Exception Handler override — konsisten untuk
  semua response termasuk error.
- S3/MinIO diakses HANYA dari Laravel (bukan Nuxt) — validasi 7-layer
  kunjungan (GPS, geofencing, live camera, watermark, EXIF, face
  detection, offline queue) wajib server-side sebelum file dianggap sah.
- Auth: Sanctum Bearer token + refresh token + device binding.
  Login Google via `laravel/socialite` (backend yang handle OAuth,
  bukan Nuxt). Login email/password standar + rate limiting.
- Role via Spatie Permission: `super_admin`, `admin_puskesmas`,
  `pj_prolanis`, `kader` — scope data per role via Policy class,
  bukan hanya middleware role.

## Sumber Data

- Data pasien & hasil lab TIDAK dimiliki di sini — ditarik (pull harian
    - webhook untuk kasus "Berat") dari SiLAKES via service
      `SyncSilakesService`, disimpan sebagai cache lokal
      (`patients_cache`, `lab_results_cache`).
- Threshold klasifikasi risiko (`risk_thresholds`) adalah milik domain
  ini, bukan SiLAKES.
- GIS aktif sekarang (bukan fase nanti): `patients_cache` punya
  `geo_status`/`latitude`/`longitude`/`geo_source` (Dokumen 2 §2c) —
  fallback centroid desa dari `master-wilayah` sebelum titik presisi
  kader tersedia via `VisitReportService`.

## Batasan Keras

- Tidak pernah menulis balik ke database SiLAKES secara langsung — **satu
  pengecualian terkontrol**: `POST .../pembaruan-lapangan` (§9 Dokumen 1),
  dipanggil dari `VisitReportService` setelah laporan kunjungan kader
  tersimpan lokal (queue job + retry, bukan synchronous call). Hasilnya
  SELALU `pending_review` di SiLAKES — PRODULI tidak perlu polling status,
  perubahan yang disetujui otomatis muncul di sync rutin berikutnya.
- Token Sanctum tidak boleh diekspos ke Nuxt sebagai localStorage value
  di dokumentasi manapun — refresh token via httpOnly cookie/secure storage.
- Layer 6 (`FaceDetectionCheck`) **non-aktif secara default** di v1 (feature-flag
  `produli.validation.face_detection_enabled`). Kalau diaktifkan nanti,
  `face_detected` hanya boolean presence — jangan pernah simpan face embedding.
