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

- Data pasien & hasil lab TIDAK dimiliki di sini: ditarik dari SiLAKES via
  `SyncSilakesService` (harian 02:00, plus tombol manual super_admin),
  disimpan sebagai cache lokal (`patients_cache`, `lab_results_cache`).
  Sejak 2026-11-17 (blueprint `docs/planning/16`): SEMUA pasien Prolanis
  ditarik (termasuk yang belum punya hasil lab), batas "data baru" disimpan
  di `integration_sync_logs.details.patients_watermark` (bukan
  `max(last_synced_at)`), dan satu kunci `produli:silakes-sync` dipakai
  sinkron harian, tombol manual, `produli:isi-ulang-pasien`, dan snapshot.
- SiLAKES MENDORONG keputusan atas usulan PRODULI seketika ke
  `POST /api/v1/integration/silakes-events` (HMAC, `SilakesEventService`):
  pendaftaran pasien baru, usulan perubahan data, penggabungan pasien.
  Hasil lab TIDAK real-time, tetap lewat sinkron 02:00 (keputusan §9 no. 5).
- Kolom baru di payload sinkron tidak terisi untuk pasien lama yang tidak
  berubah di SiLAKES: setelah menambah kolom, jalankan
  `produli:isi-ulang-pasien --kolom=...` (hanya mengisi yang kosong).
- Puskesmas pasien DIKUNCI (`puskesmas_locked_at`) sejak pertama kali
  ditetapkan; pindah hanya lewat override (`PATCH /patients/{id}/override-puskesmas`).
- Kondisi "per tanggal" memakai tanggal PEMERIKSAAN (`assessment_date`),
  bukan `computed_at`; snapshot harian di `patient_daily_snapshots` (02:30).
- Poin kinerja aturan v2 (`RiskTransitionScorer`): hanya transisi karena lab
  baru, bonus kunjungan tervalidasi +5 (bukan syarat).
- Threshold klasifikasi risiko (`risk_thresholds`) adalah milik domain
  ini, bukan SiLAKES.
- GIS aktif sekarang (bukan fase nanti): `patients_cache` punya
  `geo_status`/`latitude`/`longitude`/`geo_source` (Dokumen 2 §2c) —
  fallback centroid desa dari `master-wilayah` sebelum titik presisi
  kader tersedia via `VisitReportService`.

## Batasan Keras

- Tidak pernah menulis langsung ke database SiLAKES. Semua penulisan lewat
  endpoint integrasi terkontrol (queue job + retry), dan SELALU melewati
  persetujuan staf Labkesda (`pending_review`, atau switch auto approve yang
  dinyalakan staf di SiLAKES):
  `POST .../patients/{id}/pembaruan-lapangan` (usulan perubahan data dari
  kunjungan kader & dashboard, termasuk foto scan KTP),
  `POST .../patient-registrations` (pasien baru dari antrian pengiriman
  sampel), `POST .../prolanis-deliveries` (batch sampel tiba). Endpoint baca
  tambahan: `ktp-ocr`, `patients/{id}`, `patient-registrations/{id}`.
  Endpoint tulis baru hanya dengan keputusan pemilik sistem (lihat
  `docs/planning/16`).
- Token Sanctum tidak boleh diekspos ke Nuxt sebagai localStorage value
  di dokumentasi manapun — refresh token via httpOnly cookie/secure storage.
- Layer 6 (`FaceDetectionCheck`) **non-aktif secara default** di v1 (feature-flag
  `produli.validation.face_detection_enabled`). Kalau diaktifkan nanti,
  `face_detected` hanya boolean presence — jangan pernah simpan face embedding.
