# Dokumen Perencanaan 1/3 — Integrasi SiLAKES ↔ PRODULI

> Bagian dari rencana pengembangan **PRODULI**, sistem pendamping SiLAKES untuk UPTD Labkesda Kabupaten Sumenep.

## 1. Keputusan Arsitektur Utama

PRODULI dibangun sebagai **aplikasi terpisah** (Laravel + Nuxt sendiri), bukan modul di dalam SiLAKES.

**Alasan:**

- SiLAKES = domain inti _Laboratory Information System_ (pemeriksaan, hasil, sertifikasi ISO/IEC 17025) — dan sedang dalam fase upgrade besar (unifikasi TBM/pangan/air). Menambah domain "kunjungan rumah & penugasan kader" ke dalamnya akan melanggar _Separation of Concern_ di level sistem dan menambah risiko regresi pada modul yang sedang stabil.
- PRODULI = domain _community health operations_ (kesehatan masyarakat aktif). Karakteristik beban, siklus rilis, dan pengguna (kader lapangan lansia vs analis lab) sangat berbeda.
- Pola integrasi: **SiLAKES = source of truth data lab (read-only bagi PRODULI)**, **PRODULI = system of engagement** (kunjungan, penugasan, monitoring).

## 2. Apakah SiLAKES Butuh Tabel Tambahan?

| Kebutuhan                                                                      | Status di SiLAKES                                       | Tindakan                                                                                                                                                                                           |
| ------------------------------------------------------------------------------ | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Hasil lab per parameter (GDP, Kolesterol, Trigliserida, LDL, Ureum/Creatinine) | Kemungkinan sudah ada di struktur `pemeriksaan`/`hasil` | **Tidak perlu tabel baru** — pastikan saja field-field ini terstruktur per parameter, bukan teks bebas, agar bisa dibaca API                                                                       |
| Nilai rujukan/threshold risiko PRODULI (GDP > 120, dst.)                        | Business rule spesifik PRODULI                           | **Jangan taruh di SiLAKES.** Simpan di PRODULI sendiri (`risk_thresholds`) — ini business rule bounded context PRODULI, bukan standar lab SiLAKES. Mencampur akan melanggar prinsip domain ownership |
| Autentikasi service-to-service                                                 | Sanctum `personal_access_tokens`                        | Kalau SiLAKES belum pakai Sanctum → install (1 tabel standar bawaan, bukan tabel custom)                                                                                                           |
| Audit siapa menarik data & kapan                                               | Belum ada                                               | **Opsional tapi direkomendasikan**: 1 tabel kecil `integration_sync_logs` (service_name, endpoint, requested_at, status, records_count) — untuk observability & kepatuhan                          |
| Master wilayah (desa/kecamatan/puskesmas)                                      | Kemungkinan sudah ada                                   | Tidak duplikasi struktur — PRODULI konsumsi via API lalu cache lokal                                                                                                                                |

**Kesimpulan:** SiLAKES idealnya hanya butuh **maksimal 1 tabel teknis baru** (`integration_sync_logs`, opsional) — tidak ada tabel bisnis baru.

## 3. Endpoint yang Perlu Disediakan SiLAKES (read-only)

```
GET /api/v1/integration/patients
GET /api/v1/integration/lab-results?since={timestamp}&cursor={cursor}
GET /api/v1/integration/master-wilayah
```

Aturan wajib:

- Semua **GET only** — PRODULI tidak pernah menulis balik ke SiLAKES.
- Token discoped, rate-limited, response terstandardisasi (lihat Dokumen 2 untuk format).
- Delta sync pakai `updated_at` + cursor pagination, bukan full-dump tiap hari (efisiensi & mengurangi beban DB SiLAKES).

## 4. Pola Komunikasi Laravel ↔ Laravel

| Opsi                                                                                    | Kelebihan                                                                      | Kekurangan                                                                               |
| --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------- |
| **A. Pull terjadwal** (scheduler tiap 48 jam — keputusan final, lihat catatan di bawah) | Simpel, decoupled, SiLAKES tidak perlu tahu PRODULI                             | Delay hingga 48 jam untuk kasus "Berat" yang butuh respons cepat                         |
| **B. Push via webhook**                                                                 | Real-time untuk kasus prioritas tinggi                                         | SiLAKES jadi "tahu" tentang PRODULI (coupling), butuh retry queue + idempotency di PRODULI |
| **C. Hybrid (rekomendasi, webhook belum dibangun)**                                     | Gabungan A + B: pull 48 jam untuk full sync, webhook khusus kategori **Berat** | Kompleksitas implementasi sedikit lebih tinggi, tapi sepadan dengan risikonya            |

**Rekomendasi: Hybrid**, tapi saat ini baru bagian A (pull terjadwal) yang dibangun — webhook untuk kasus Berat belum dikerjakan. Cadence pull **48 jam** (bukan harian seperti draft awal) — keputusan final dari Anda. Konsekuensi: kasus "Berat" bisa telat terdeteksi hingga 48 jam sampai webhook (bagian B) dibangun — kalau ini jadi masalah nyata di pilot, itu alasan kuat untuk prioritaskan webhook lebih awal.

**Catatan implementasi cadence 48 jam:** jangan pakai cron `*/2` di field hari-bulan (`0 2 */2 * *`) — pola itu berbasis tanggal kalender (ganjil/genap), bukan interval 48 jam murni, dan patah di batas bulan (mis. tanggal 31 ke 1). Pakai pola "cek kapan run terakhir sukses, jalan tiap hari tapi skip kalau belum 48 jam" — lebih robust.

## 5. Autentikasi Antar Layanan

| Opsi                                         | Catatan                                                                      |
| -------------------------------------------- | ---------------------------------------------------------------------------- |
| Sanctum token + `abilities` (scope)          | Native Laravel, ringan, cukup untuk service internal                         |
| Laravel Passport (OAuth2 client_credentials) | Lebih standar tapi overhead setup besar — overkill untuk 1 konsumen internal |
| API Key + HMAC signature                     | Baik sebagai lapisan tambahan, mencegah replay attack                        |

**Rekomendasi: Sanctum token + HMAC signature** (defense-in-depth), tanpa perlu OAuth2 penuh.

Implementasi ringkas di sisi SiLAKES:

- Buat 1 user khusus `service-account-kopipu`, generate token dengan ability `integration:read-lab-results`.
- Middleware: `EnsureTokenAbility` + `throttle:60,1`.
- Setiap request PRODULI menyertakan header `X-Signature = HMAC-SHA256(body + timestamp, shared_secret)`; SiLAKES verifikasi sebelum memproses.
- Token disimpan sebagai env var di PRODULI (`SILAKES_API_TOKEN`), rotasi berkala (mis. tiap 90 hari).

## 6. Keamanan & Kepatuhan Data

- Data pasien + hasil lab = **data pribadi spesifik** (kategori kesehatan) menurut UU PDP No. 27/2022 → wajib TLS/HTTPS meski di jaringan internal, prinsip _least privilege_ (token read-only, scope sempit), dan audit log akses.
- **NIK**: dikirim dalam bentuk **HMAC-SHA256 berkunci** (bukan SHA256 polos) memakai secret khusus terpisah (`PRODULI_NIK_HASH_SECRET`) — penting karena struktur NIK bisa ditebak (kode wilayah + tanggal lahir), sehingga hash tanpa kunci rawan dibongkar lewat rainbow table/brute-force. `patient_id` internal tetap jadi referensi utama antar sistem; NIK asli tidak pernah dikirim ke PRODULI. _(Sudah diimplementasikan sesuai ini di sisi SiLAKES — lihat kontrak API aktual.)_
- Rate limiting wajib di endpoint integrasi.
- IP allowlist bila infrastruktur mendukung (hanya server PRODULI yang boleh memanggil endpoint ini).

## 7. Risiko & Catatan

- **Risiko duplikasi data saat sync** → mitigasi dengan idempotency key per record.
- **Risiko SiLAKES down saat PRODULI butuh data** → PRODULI wajib punya local read-cache (detail di Dokumen 2) agar tetap operasional saat SiLAKES maintenance.
- Trade-off pull vs push sudah dibahas di poin 4 — pastikan tim SiLAKES setuju sebelum implementasi webhook (karena menambah dependency keluar dari SiLAKES).

## 8. Checklist Aksi Tim SiLAKES

- [ ] Konfirmasi Sanctum sudah terpasang (install bila belum)
- [ ] Buat service-account user + token dengan ability terbatas
- [ ] Implementasi 3 endpoint integrasi read-only di atas
- [ ] Tambahkan middleware throttle + ability check + verifikasi HMAC
- [ ] (Opsional) tabel `integration_sync_logs`
- [ ] Dokumentasikan kontrak API di Swagger yang sudah ada di stack SiLAKES

## Lampiran A — Kontrak API Final (Hasil Implementasi Tahap 1)

_Bagian §1–§8 di atas adalah rencana awal. Bagian ini kontrak aktual yang sudah live di SiLAKES — jadikan acuan utama saat membangun client di PRODULI backend, bukan §3–§5 di atas._

**Autentikasi:** `Authorization: Bearer {{SILAKES_API_TOKEN}}` + header `X-Signature`/`X-Timestamp` (HMAC-SHA256 atas `<raw_body>.<timestamp>`, toleransi selisih waktu 300 detik). Rate limit 60 req/menit (429 saat kena limit). Error: 401 (token/signature invalid), 403 (ability kurang), 422 (query salah).

**Endpoint 1 — `GET /api/v1/integration/patients`:**

- `nik_hash` sudah di-HMAC-hash di sisi SiLAKES (bukan NIK asli, tidak bisa dibalik) → gunakan `patient_id` sebagai referensi utama.
- `kel_desa`/`kecamatan` **teks bebas** hasil input manual, belum berupa kode wilayah baku.

**Endpoint 2 — `GET /api/v1/integration/lab-results`:**

- Hanya hasil **FINAL** (`status=completed` DAN `status_konfirmasi=approved`) yang muncul.
- `nilai_rujukan` = standar lab SiLAKES, **bukan** threshold risiko PRODULI (threshold risiko tetap terpisah di `risk_thresholds`, Dokumen 2 §2).
- `hasil` bertipe **string**, bukan number.

**Endpoint 3 — `GET /api/v1/integration/master-wilayah`:** hierarki provinsi/kabupaten/kecamatan/desa kode baku, **tidak ada level puskesmas** — tetap dikelola sendiri di PRODULI.

### Catatan Implementasi Wajib di PRODULI

1. **Normalisasi `kel_desa`/`kecamatan`.** Karena teks bebas rawan typo/variasi, jangan langsung dipakai sebagai kunci agregasi peta sebaran/ranking kecamatan di dashboard Dinkes. Simpan teks asli apa adanya di `patients_cache`, tambahkan kolom `desa_code` nullable yang diisi lewat proses matching terpisah (fuzzy match ke `master-wilayah` + verifikasi manual admin untuk kasus ambigu). Data yang belum termapping ditandai "belum terverifikasi" di dashboard — jangan digabung paksa ke desa terdekat.
2. **Parsing `hasil` defensif.** `RiskClassificationService` harus menangani nilai non-numerik pada field `hasil` dengan fallback jelas (skip klasifikasi parameter itu + log), bukan exception yang menggagalkan seluruh proses sync.

## 9. Endpoint Tulis Terkontrol — Pembaruan Data Lapangan oleh Kader (AKTIF SEKARANG)

**Kebutuhan ini sekarang aktif**, bukan lagi "nanti" — kader perlu mencatat titik GPS rumah pasien **dan** melengkapi/mengoreksi data pribadi pasien yang tidak akurat saat kunjungan lapangan ("sembari menyelam minum air"), lalu disinkronkan balik ke SiLAKES.

### Endpoint baru

```
POST /api/v1/integration/patients/{patient_id}/pembaruan-lapangan
```

Ini **satu-satunya pengecualian** dari aturan "SiLAKES read-only" di dokumen ini — sengaja dibuat sempit (field spesifik, bukan endpoint tulis umum untuk data pasien), pakai ability token terpisah (`integration:write-field-data`), **tidak** reuse token baca yang sudah ada.

Request body (semua optional kecuali identifier pasien di URL):

```json
{
    "latitude": -7.0123,
    "longitude": 113.8456,
    "alamat": "...",
    "kel_desa": "...",
    "kecamatan": "...",
    "rt_rw": "...",
    "phone": "...",
    "pekerjaan": "...",
    "status_perkawinan": "...",
    "sumber": "kopipu_kunjungan",
    "kopipu_visit_id": 789,
    "kopipu_kader_nama": "Bu Siti"
}
```

### Aturan keselamatan — semua update WAJIB diverifikasi staf Labkesda

**Keputusan terbaru (menggantikan rancangan auto-isi sebelumnya):** tidak ada lagi auto-apply untuk kategori apa pun, termasuk field kontak/alamat yang tadinya kosong. **Semua** data dari PRODULI — geo, kontak/alamat, maupun identitas — masuk sebagai usulan berstatus `pending_review`, dan baru berlaku setelah disetujui staf Labkesda lewat halaman persetujuan baru di SiLAKES. Ini kebijakan Dinkes, bukan lagi keputusan teknis semata: data pasien pemerintah wajib melalui verifikasi manusia sebelum berubah.

| Kategori          | Contoh field                                                                                                                                                                                                                                                                                                                                             | Perlakuan                                                                                                                            |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| **Geo**           | `latitude`, `longitude`                                                                                                                                                                                                                                                                                                                                  | Diusulkan sebagai titik lokasi rumah — tetap perlu konfirmasi staf sebelum jadi lokasi resmi (bukan lagi auto-additive tanpa review) |
| **Kontak/alamat** | `alamat`, `kel_desa`, `kecamatan`, `rt_rw`, `phone`, `pekerjaan`, `status_perkawinan`, **`golongan_darah`, `agama`, `is_bpjs`, `no_bpjs`, `jenis_prolanis`, `jenis_perokok`** (diperluas — dikonfirmasi sesuai desain UI final `/app/kunjungan/[id]`, konsep "sembari menyelam minum air": kader lengkapi data pasien semaksimal mungkin saat kunjungan) | Selalu `pending_review`, baik kolom lama kosong maupun terisi                                                                        |
| **Identitas**     | `nik`, `name`, `tgl_lahir`, `gender`                                                                                                                                                                                                                                                                                                                     | Selalu `pending_review` — risiko tertinggi, staf wajib bandingkan dengan dokumen resmi sebelum approve                               |

### Halaman Persetujuan Pembaruan Data Pasien (deliverable baru, wajib)

**Dua lapis terpisah, karena SiLAKES pakai Vue 3 sebagai frontend (bukan Blade):**

- **Backend (Laravel):** endpoint API — `GET` daftar usulan `pending_review`, `POST` approve, `POST` reject (dengan catatan opsional) — format response standar SiLAKES seperti biasa.
- **Frontend (Vue 3):** komponen/halaman baru yang mengonsumsi endpoint di atas — daftar usulan, **nilai lama vs nilai usulan** berdampingan per field, sumber (`kopipu_kunjungan`, nama kader, tanggal kunjungan), tombol **Setujui**/**Tolak**. Ikuti konvensi Vue yang **sudah ada** di project ini (struktur routing, state management, komponen admin lain yang mirip) — jangan buat pola baru.

Approve/reject tercatat siapa & kapan (`reviewed_by`, `reviewed_at`).

### Keputusan atas pertanyaan terbuka (dijawab setelah tim SiLAKES minta klarifikasi)

1. **Tempat simpan "alamat domisili terverifikasi":** tabel baru terpisah **`patient_domiciles`** (bukan kolom baru di `patients`) — `id`, `patient_id`, `alamat`, `kel_desa`, `kecamatan`, `rt_rw`, `latitude`, `longitude`, `sumber`, `verified_by_update_id` (FK ke `patient_field_updates`), `created_at`, `updated_at`. Alasan: konsep ini beda sifat dari data registrasi/KTP di `patients` — punya siklus hidup, sumber, dan metadata audit sendiri; mencampur ke `patients` akan mengotori tabel yang dipakai banyak modul lain.
2. **Endpoint tulis wajib HMAC juga:** **Ya, wajib**, sama seperti endpoint baca (`X-Signature`/`X-Timestamp`, window 300 detik) — untuk endpoint tulis ini justru lebih penting, bukan kurang, karena request yang dipalsukan bisa mengubah data, bukan cuma membaca.
3. **Role yang boleh approve/reject:** buat permission granular baru `approve-patient-field-updates` (jangan reuse role admin umum yang sudah ada) — assign ke role setingkat supervisor/penanggung jawab data di hierarki SiLAKES yang sudah berjalan, bukan staf entri data biasa. Tim SiLAKES yang paling tahu role mana yang paling tepat dipetakan.
4. **Cukup API dulu atau langsung UI lengkap:** **keduanya dibutuhkan untuk v1, tapi bertahap** — bangun endpoint approve/reject dulu (bagian dari §9 di atas, tervalidasi lewat Postman/tinker), baru halaman Vue di atas sebagai pekerjaan terpisah setelahnya. Staf Labkesda tidak akan memakai API mentah, jadi UI bukan opsional — hanya diurutkan belakangan supaya logic intinya benar dulu sebelum dibungkus tampilan.

### Tabel baru yang dibutuhkan di SiLAKES (disatukan — 1 tabel, bukan 2)

`patient_field_updates`: `id`, `patient_id`, `kategori` (`geo`/`kontak`/`identitas` — untuk pelaporan saja, tidak mengubah alur), `field_name` (nullable untuk geo), `old_value`, `new_value` (JSON untuk geo: `{"latitude":...,"longitude":...}`), `sumber` (`kopipu_kunjungan`), `kopipu_visit_id`, `kopipu_kader_nama`, `status` (`pending_review`/`approved`/`rejected`), `reviewed_by`, `reviewed_at`, `catatan_reviewer`, `created_at`.

**Penting:** field alamat hasil approve **tidak boleh menimpa** alamat KTP resmi — simpan sebagai data terpisah ("alamat domisili terverifikasi"), karena alamat KTP dan domisili aktual sering berbeda di lapangan (hal normal di Indonesia).

**PRODULI tidak perlu polling status approval.** Begitu staf approve, field terkait di `patients` (atau tabel alamat terpisah) berubah, dan sinkronisasi rutin PRODULI (`updated_at` delta sync, Dokumen 2 §3) otomatis menangkap perubahan itu di siklus berikutnya. Kalau ditolak, tidak ada perubahan — cukup untuk v1, endpoint status terpisah bisa ditambah nanti kalau benar-benar dibutuhkan (YAGNI).

### Data yang Perlu Disiapkan Tim SiLAKES (untuk Tahap 2 — matching wilayah lama)

Berbeda dari endpoint `/wilayah/*` yang sudah ada (dipakai dropdown SiLAKES sendiri), permintaan berikut khusus untuk konteks integrasi PRODULI:

1. **Distribusi teks mentah**: `SELECT kecamatan, kel_desa, COUNT(*) FROM patients GROUP BY kecamatan, kel_desa ORDER BY COUNT(*) DESC` — untuk tahu skala nyata variasi format (dugaan: jumlah _varian teks unik_ jauh lebih kecil dari jumlah pasien, karena satu varian dipakai berulang oleh banyak pasien — jadi pekerjaan matching manual terbatas per varian, bukan per pasien).
2. **Konfirmasi perilaku form pasien baru**: sejak dropdown `/wilayah/*` (nusa) dipakai beberapa bulan terakhir, apakah nilai yang tersimpan ke `patients.kel_desa`/`kecamatan` persis nama dari nusa (`Village::name`/`District::name`), atau user masih bisa override manual/free-type? Ini menentukan apakah pasien baru butuh matching sama sekali atau otomatis 1:1.
3. **Jumlah `kel_desa` NULL vs terisi** — untuk konfirmasi skala kasus `unknown` (lihat dokumen 02 §2a).
4. **Tambahkan `latitude`/`longitude` ke response `GET /api/v1/integration/master-wilayah`** (endpoint integrasi PRODULI, bukan `/wilayah/*` dropdown) — **dibutuhkan sekarang** untuk fallback GIS di Dokumen 2 §2c, sebelum titik presisi dari kader tersedia. Kolomnya sudah terverifikasi tersedia di tabel nusa, tinggal disertakan di response; perlu dicek juga apakah datanya sudah terisi (bukan NULL semua) di instalasi SiLAKES saat ini.
5. **Bangun endpoint `POST /api/v1/integration/patients/{patient_id}/pembaruan-lapangan`** sesuai §9 di atas — termasuk tabel baru `patient_field_updates` (disatukan), ability token terpisah `integration:write-field-data`, dan **halaman persetujuan pembaruan data pasien** (UI staf Labkesda) — semua usulan wajib direview manual, tidak ada auto-apply.

---

_Lanjut ke Dokumen 2: Arsitektur Backend PRODULI._
