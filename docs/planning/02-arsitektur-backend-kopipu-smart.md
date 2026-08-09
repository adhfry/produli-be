# Dokumen Perencanaan 2/3 — Arsitektur Backend KOPIPU Smart (Laravel 11)

## 1. Prinsip Arsitektur

Layered architecture: **Controller → Service Layer → Repository → Model**, dengan Form Request untuk validasi dan API Resource untuk shaping response. Business rule tidak boleh ada di Controller (tipis, hanya orkestrasi).

```
app/
├── Http/
│   ├── Controllers/Api/V1/
│   ├── Requests/
│   └── Resources/
├── Services/            # business logic
├── Repositories/         # akses data, kontrak via interface
├── Models/
├── DTO/                  # data transfer object antar layer
└── Support/ApiResponse.php
```

## 2. Skema Database (Entitas Utama)

| Tabel                   | Fungsi                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `users`                 | Semua pengguna KOPIPU; role via Spatie Permission                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `kabupaten`             | Ringan, saat ini cukup 1 baris (Sumenep) — disiapkan untuk skala provinsi di masa depan                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `kecamatan`             | Hierarki administratif **tetap**, `kecamatan_id` sinkron dengan kode BPS/Kemendagri di SiLAKES                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `puskesmas`             | **Dikelola manual di KOPIPU**, entitas operasional — SiLAKES tidak punya level puskesmas sama sekali. Tidak punya FK ke kecamatan (lihat §2a). **Kolom tambahan (§15):** `alamat` (text), `no_telp`, `no_wa` (nullable), `latitude`/`longitude` (nullable), `deskripsi` (text nullable) — TETAP tabel `puskesmas`, JANGAN digeneralisasi jadi tabel `instansi` polimorfik (`puskesmas_id` sudah dipakai luas di seluruh sistem, refactor itu risiko tinggi untuk manfaat yang belum nyata — kalau perlu jenis instansi lain nanti, itu keputusan terpisah)                                                                                                                                                                                  |
| `desa`                  | Titik temu 2 hierarki independen: `kecamatan_id` (administratif, tetap) DAN `puskesmas_id` (operasional/wilayah kerja, bisa berubah) — lihat §2a untuk alasan desain                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| `wilayah_mapping`       | Rekonsiliasi teks bebas `kel_desa`/`kecamatan` dari data pasien SiLAKES → `desa_id` baku KOPIPU. **Wajib** karena field ini di SiLAKES masih hasil input manual, belum berupa kode wilayah                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `patients_cache`        | Cache pasien: `external_patient_id`, `nik_hash`, `nama`, `alamat`, `kel_desa_raw`, `kecamatan_raw` (teks asli dari SiLAKES), `desa_id` (nullable), `wilayah_status` (`resolved`/`unresolved`/`unknown` — lihat §2a), `last_synced_at`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `lab_results_cache`     | Hasil lab per parameter: `external_id`, `patient_id`, `parameter`, `value`, `tanggal_periksa`, `synced_at`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `risk_thresholds`       | Config threshold per parameter (data-driven, bisa diubah admin tanpa deploy)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `risk_classifications`  | Hasil kalkulasi risiko: `patient_id`, `level`, `computed_at`, `criteria_snapshot` (json, untuk audit histori)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| `kader`                 | `user_id`, `pj_id` (FK ke `users.id` — **bukan** ke `kader.id` — supaya PJ yang murni supervisor tanpa baris kader sendiri tetap valid jadi target; PJ bisa merangkap kader tapi tidak wajib), `puskesmas_id`, `status_aktif`, `no_hp`, `no_wa` (nullable, beda dari no_hp — dipakai `NotificationService` §8), `alamat`, `gender`, `tgl_lahir` — `email` TIDAK diduplikasi di sini, pakai `users.email` yang sudah ada                                                                                                                                                                                                                                                                                                                     |
| `visit_assignments`     | `patient_id`, `kader_id`, `assigned_by`, `scheduled_date`, `status`, `priority`, `puskesmas_id_snapshot` (di-_snapshot_ saat assignment dibuat — lihat §2a, agar laporan historis tidak berubah kalau `desa.puskesmas_id` direassign di kemudian hari)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| `visit_reports`         | `assignment_id`, `gps_lat/lng`, `photo_path`, `exif_meta` (json), `face_detected` (bool), `kondisi`, `catatan`, `sync_status`, `pj_reviewed_by`/`pj_reviewed_at`, `validation_status` (`pending`/`valid`/`invalid`), `validated_by`/`validated_at`/`validation_note` — lihat §11. **Pemeriksaan saat kunjungan (semua nullable, opsional):** `gda` (Gula Darah Acak), `gdp` (Gula Darah Puasa), `gd2jpp` (Gula Darah 2 Jam Post Prandial), `uric_acid`, `cholesterol`, `systolic`/`diastolic` (tensi). **`keluhan`** (text, keluhan yang disampaikan pasien saat kunjungan). **`tindakan`** (enum: `diberi_obat`/`dirujuk_puskesmas`/`tidak_ada` — karena target kunjungan cuma pasien Berat, hampir selalu salah satu dari 2 yang pertama) |
| `reminders`             | `assignment_id`, `channel`, `scheduled_at`, `sent_at`, `status`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `integration_sync_logs` | Log tarik data dari SiLAKES                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `audit_logs`            | Via `spatie/laravel-activitylog` — siapa ubah apa, kapan                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| `account_activations`   | `user_id`, `token_hash` (pola sama seperti `refresh_tokens.token_hash`), `expires_at` (default 7 hari), `used_at`, `invited_by`. Alur invite-by-email: super_admin daftarkan admin_puskesmas/pj_prolanis (`POST /api/v1/staff`), PJ daftarkan kader (`POST /api/v1/kader`) — User baru dapat email aktivasi, klik link → `POST /api/v1/auth/activate` generate password random (ditampilkan sekali) + wajib ganti password di login pertama                                                                                                                                                                                                                                                                                                 |

## 2a. Desain Master Wilayah — Detail

**Masalah inti:** Kecamatan (batas administratif BPS/Kemendagri, tetap) dan Puskesmas (penugasan operasional Dinkes, bisa berubah) adalah dua sistem klasifikasi yang independen. Kabupaten Sumenep punya kasus 1 Kecamatan → banyak Puskesmas — **dikonfirmasi 3 dari 27 kecamatan**: Kota (Puskesmas Pandian & Pamolokan), Batang-Batang (Puskesmas Batang-Batang & Puskesmas Legung Timur), Lenteng (Puskesmas Lenteng & Puskesmas Moncek Tengah). 24 kecamatan sisanya masing-masing 1 puskesmas (contoh tervalidasi: Gapura → Puskesmas Gapura). Karena nama puskesmas **tidak selalu** sama dengan nama kecamatan (lihat Legung Timur, Moncek Tengah), jangan asumsikan pola penamaan otomatis untuk 24 kecamatan lain — tetap perlu daftar nama puskesmas aslinya per kecamatan, bukan cuma diturunkan dari nama kecamatan. Karena inilah Puskesmas **tidak boleh** dinested di bawah Kecamatan atau sebaliknya.

**Keputusan:** `desa` punya 2 FK independen — `kecamatan_id` (administratif) dan `puskesmas_id` (operasional/wilayah kerja). Titik temu dua hierarki ada di level Desa, bukan salah satu jadi induk yang lain. `puskesmas` sendiri **tidak** punya FK ke kecamatan (kalaupun perlu alamat kantor, simpan sebagai teks bebas, jangan dijadikan basis logic wilayah kerja).

Konsekuensi langsung dari desain ini:

- 1 kecamatan banyak puskesmas → otomatis didukung, tidak perlu penanganan khusus.
- Desa pindah wilayah kerja ke puskesmas lain tanpa pindah kecamatan → cukup `UPDATE desa SET puskesmas_id = ...`, tidak ada perubahan skema.
- Puskesmas melayani desa lintas kecamatan (kebijakan masa depan) → sudah didukung hari ini, karena `puskesmas_id` di `desa` tidak bergantung pada `kecamatan_id`.
- Reassignment wilayah kerja tidak boleh mengubah histori laporan → `visit_assignments.puskesmas_id_snapshot` diisi dari `desa.puskesmas_id` **saat assignment dibuat**, immutable setelahnya. Laporan bulan lalu tetap menunjukkan puskesmas yang benar meski wilayah kerja sudah direassign hari ini.

**Skema (DDL ringkas):**

```sql
CREATE TABLE kecamatan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kabupaten_id BIGINT UNSIGNED NOT NULL,
  kode_kemendagri VARCHAR(10) UNIQUE,
  nama VARCHAR(100) NOT NULL,
  latitude DOUBLE NULL,   -- dari master-wilayah SiLAKES; bisa NULL, jangan asumsikan selalu ada
  longitude DOUBLE NULL,
  FOREIGN KEY (kabupaten_id) REFERENCES kabupaten(id)
);

CREATE TABLE puskesmas (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kabupaten_id BIGINT UNSIGNED NOT NULL,
  kode_internal VARCHAR(20) UNIQUE,
  nama VARCHAR(100) NOT NULL,
  alamat TEXT NULL,              -- informatif saja, BUKAN dasar logic wilayah kerja
  status_aktif BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (kabupaten_id) REFERENCES kabupaten(id)
);

CREATE TABLE desa (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kecamatan_id BIGINT UNSIGNED NOT NULL,      -- administratif, TETAP
  puskesmas_id BIGINT UNSIGNED NULL,          -- operasional, BISA berubah
  kode_kemendagri VARCHAR(15) UNIQUE,
  nama VARCHAR(100) NOT NULL,
  latitude DOUBLE NULL,   -- dari master-wilayah SiLAKES (~99% terisi, sisanya NULL — lihat Dokumen 04)
  longitude DOUBLE NULL,
  FOREIGN KEY (kecamatan_id) REFERENCES kecamatan(id),
  FOREIGN KEY (puskesmas_id) REFERENCES puskesmas(id) ON DELETE SET NULL,
  INDEX idx_desa_kecamatan (kecamatan_id),
  INDEX idx_desa_puskesmas (puskesmas_id)
);

CREATE TABLE wilayah_mapping (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kel_desa_raw VARCHAR(150) NOT NULL,
  kecamatan_raw VARCHAR(150) NULL,
  desa_id BIGINT UNSIGNED NULL,
  status ENUM('matched','unresolved') NOT NULL DEFAULT 'unresolved',
  matched_at TIMESTAMP NULL,
  matched_by BIGINT UNSIGNED NULL,
  FOREIGN KEY (desa_id) REFERENCES desa(id),
  UNIQUE KEY uq_raw_text (kel_desa_raw, kecamatan_raw)
);
```

**Strategi normalisasi untuk exact-match** (sebelum kasus sisa masuk fuzzy-match/manual review): samakan huruf besar, buang semua karakter non-alfanumerik (apostrof, spasi ganda, tanda hubung), baru bandingkan. Contoh dari data nyata: `KALIMO'OK`, `KALIMOOK`, `KALIMO OK` → semua jadi key `KALIMOOK`; `DESA KOLOR`, `KOLOR`, `KELURAHAN KOLOR` → semua jadi `KOLOR`. Lakukan pencarian **di dalam scope kecamatan yang sama** (setelah kecamatan juga dinormalisasi) untuk mengurangi risiko ambiguitas nama desa yang kebetulan sama di kecamatan berbeda.

**Penanganan pasien dengan `kel_desa` kosong (alamat tidak diketahui):** ini kasus data quality yang beda dari "teks ada tapi tidak match" — dibedakan lewat `patients_cache.wilayah_status`:

| Status         | Kondisi                                                                                         | Penanganan                                                                                                             |
| -------------- | ----------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `resolved`     | `desa_id` berhasil di-mapping                                                                   | Bisa di-assign ke kader untuk kunjungan                                                                                |
| `unresolved`   | `kel_desa_raw` terisi tapi tidak ada match di `wilayah_mapping`/`desa` (typo, penulisan beda)   | Masuk antrean review manual admin — perlu dicek nama variasinya                                                        |
| `unknown`      | `kel_desa_raw` kosong/null dari SiLAKES sejak awal                                              | Masuk antrean "lengkapi data pasien" — beda sifatnya dari `unresolved`, ini murni data belum ada, bukan gagal matching |
| `out_of_scope` | `kecamatan_raw` tidak match ke salah satu kecamatan Kabupaten Sumenep (pasien dari luar daerah) | Dikecualikan dari program KOPIPU — bukan backlog kerja, jangan masuk antrean review                                    |

Aturan bisnis: `VisitAssignmentService` **menolak** assignment untuk pasien dengan `wilayah_status != 'resolved'` (tidak masuk akal mengirim kader ke alamat yang tidak diketahui). Dashboard Dinkes/Puskesmas tetap menampilkan jumlah pasien `unresolved`/`unknown` sebagai kartu metrik tersendiri (bukan disembunyikan) — supaya jadi item kerja yang terlihat, bukan silently dropped.

**Kenapa `patients_cache` tidak juga menyimpan `kecamatan_id`/`puskesmas_id` langsung (denormalisasi):** itu akan jadi redundansi yang rawan basi kalau `desa.puskesmas_id` direassign — data pasien perlu di-update massal setiap kali ada reassignment wilayah kerja. Dengan skala ~620 pasien pilot (dan proyeksi puluhan ribu se-Kabupaten Sumenep), JOIN `patients_cache → desa → puskesmas` dengan index yang tepat tetap cepat — tidak ada alasan kuat untuk denormalisasi di tabel sumber. Kalau nanti dashboard tertentu genuinely lambat, solusinya adalah database VIEW read-only (`v_patient_wilayah`) yang meng-encapsulate JOIN ini di satu tempat, bukan menambah kolom redundan ke `patients_cache`.

**Catatan desain:** `risk_classifications` di-_versioning_ (bukan overwrite) agar histori perubahan level risiko pasien bisa diaudit — penting untuk pertanggungjawaban ke Dinkes.

## 2b. Bagaimana Puskesmas "Otomatis Tahu" Wilayah Mereka

**Solusi konkret:** setelah setup awal (Dinkes assign `desa.puskesmas_id` sekali di awal, ~ratusan desa, dilakukan manual/bulk-import spreadsheet — bukan pekerjaan berulang), **semuanya otomatis** dari situ. Dashboard puskesmas cukup query `WHERE desa.puskesmas_id = current_user.puskesmas_id` — tidak ada assignment manual per pasien.

**Koreksi dari asumsi sebelumnya:** data aktual (`GROUP BY kecamatan, kel_desa` pada tabel `patients`) menunjukkan **kecamatan TIDAK selalu terisi** — ada 5.377 pasien dengan kecamatan **dan** `kel_desa` sama-sama NULL (di luar 2.389 pasien lain yang kecamatan terisi tapi `kel_desa` kosong). `resolvePuskesmas()` butuh cabang tambahan untuk kasus ini:

```php
function resolvePuskesmas(Patient $p): ?int
{
    if ($p->desa_id) {
        return $p->desa->puskesmas_id; // presisi
    }
    if (! $p->kecamatan_id) {
        return null; // tidak ada pijakan wilayah sama sekali — lihat rekomendasi scoping di bawah
    }
    $candidates = Puskesmas::whereHas('desa', fn ($q) =>
        $q->where('kecamatan_id', $p->kecamatan_id)
    )->pluck('id');

    // Sebagian besar kecamatan di Sumenep cuma punya 1 puskesmas —
    // kalau begitu, aman diinfer dari kecamatan saja tanpa desa.
    return $candidates->count() === 1 ? $candidates->first() : null;
}
```

Simpan hasilnya di `patients_cache.puskesmas_resolution_method` (`desa` | `kecamatan_fallback` | `manual` | `kader_verified` | `unresolvable`).

**Data aktual (dikonfirmasi lewat query, bukan lagi dugaan):** dari 6.442 pasien Prolanis, **4.815 (74,7%) sama sekali tidak punya data wilayah** (kecamatan maupun desa NULL), 1.265 (19,6%) hanya kecamatan yang terisi, dan hanya **362 (5,6%)** punya kecamatan+desa lengkap (43 varian teks unik, terverifikasi bersih dari data sampah). Hipotesis awal saya salah arah — filter `is_prolanis=1` **tidak** mengecilkan masalah seperti dugaan, proporsinya justru lebih berat dibanding populasi umum.

**Hasil final setelah 31 puskesmas & 334 desa selesai di-mapping:** 1.286 dari 6.442 (20%) pasien Prolanis langsung `resolved` — 355 via desa presisi, **931 via `kecamatan_fallback` tanpa kerja tambahan apa pun** (validasi desain ini bekerja seperti dirancang). Sisa 5.156 (80%) genuinely butuh kampanye telepon Puskesmas/PJ (mayoritas — 4.815 — sama sekali tidak punya kecamatan; sisanya kecamatan multi-puskesmas yang desa-nya belum diketahui, sengaja tidak ditebak). Pilot Gapura sendiri sudah 326 pasien resolved sejak sebelumnya.

**Jalan keluar untuk bucket 74,7% yang buntu:** kolom `patients.phone` NOT NULL untuk semua pasien — bisa dipakai Puskesmas/PJ Prolanis untuk kampanye telepon melengkapi data wilayah SEBELUM penugasan kader (karena tanpa wilayah, tidak ada dasar kirim kader). Ini proses operasional di luar kode KOPIPU, dijalankan paralel dengan pilot, bukan syarat sebelum pilot mulai.

**Keputusan scoping final: opsi hybrid.** Luncurkan pilot dengan populasi `wilayah_status IN ('resolved','kecamatan_fallback')` yang tersedia hari ini (362 + sebagian dari 1.265, tergantung berapa kecamatan yang cuma punya 1 puskesmas), jalankan kampanye telepon paralel untuk bucket NULL — begitu data terlengkapi, pasien otomatis masuk pool aktif tanpa perubahan sistem, karena `wilayah_status` memang dirancang sebagai status yang bisa berubah seiring waktu, bukan penilaian sekali jalan.

**Kategori tambahan yang ditemukan dari sampel data nyata** (bukan sekadar "typo variasi", butuh penanganan berbeda):

- **Placeholder/junk** (`"000"`, `"-"`, `"LAINNYA"`, kode numerik seperti `"004"`) — bukan nama desa sungguhan. Filter di awal proses matching, langsung `unresolved` tanpa dicoba fuzzy-match.
- **Encoding rusak** (`"KOTA SUM????"` muncul berulang) — kemungkinan besar korupsi karakter dari import data lama, hampir pasti dimaksud `"KOTA SUMENEP"`. Bisa jadi 1 aturan deterministik (`"KOTA SUM????"` → `"KOTA SUMENEP"`) alih-alih manual per baris.
- **Luar wilayah kerja** (`SIDOARJO`, `SURABAYA`, `MADIUN`, `MAGELANG UTARA`, dll.) — pasien valid tapi di luar cakupan kader Sumenep. Tambahkan status `out_of_scope` (bukan `unresolved`) — kecamatan yang tidak match ke salah satu ~27 kecamatan Sumenep otomatis masuk kategori ini, supaya tidak salah masuk antrean kerja admin.

## 2c. GIS untuk Lokasi Belum Presisi & Verifikasi oleh Kader

Pisahkan dua konsep independen pada `patients_cache` — jangan digabung jadi satu status:

| Field                                                                | Fungsi                                                          |
| -------------------------------------------------------------------- | --------------------------------------------------------------- |
| `wilayah_status`                                                     | Resolusi **administratif** (desa/puskesmas mana) — dari §2a/§2b |
| `geo_status` (`unknown`\|`approximate`\|`verified`)                  | Presisi **titik koordinat** untuk GIS & navigasi kader          |
| `latitude`, `longitude`                                              | Nullable — diisi sesuai `geo_status`                            |
| `geo_source` (`desa_centroid`\|`patient_reported`\|`kader_verified`) | Asal koordinat                                                  |
| `geo_verified_by`, `geo_verified_at`                                 | Audit — kader/user mana, kapan                                  |

**Tampilan GIS saat `geo_status` belum `verified`:** pakai koordinat centroid desa sebagai fallback. **Terverifikasi langsung dari source code `creasico/laravel-nusa`**: tabel `villages`/`districts`/`regencies`/`provinces` semuanya punya kolom `latitude`, `longitude` (nullable) dan `coordinates` (kemungkinan besar polygon batas wilayah) — data ini sudah tersedia di paket yang sudah terpasang, tim SiLAKES tinggal konfirmasi kolomnya sudah terisi (tergantung seeder yang dipakai saat instalasi). Tandai visual berbeda untuk fallback ini (mis. pin transparan/pola titik-titik, atau render polygon batas desa kalau `coordinates` terisi) — bukan pin presisi palsu. Ini jujur ke pengguna: "perkiraan area", bukan "ini rumahnya".

**Verifikasi oleh kader ("sembari menyelam minum air") — ide bagus, saya rancang jadi bagian dari `VisitReportService`:**

- Layer 1 (GPS aktif) di 7-layer validation _sudah_ menangkap koordinat kader saat submit laporan kunjungan — koordinat ini **jadi kandidat** lokasi rumah pasien.
- Kalau `geo_status != verified`: tampilkan konfirmasi kecil di form laporan kunjungan — "Ini titik lokasi rumah [nama pasien]?" (1 tombol besar, sesuai prinsip senior-friendly) → kalau ya, simpan sebagai `geo_status = verified`, `geo_source = kader_verified`.
- Kalau `wilayah_status != resolved` atau ada field alamat kosong: tambahkan mini-form "lengkapi data" di layar yang sama (desa dropdown/autocomplete, alamat detail, RT/RW) — kader yang sedang di lokasi jauh lebih akurat mengisi ini daripada admin di kantor.

**Soal push balik ke SiLAKES ("endpoint rumah warga") — aktif sekarang, bukan ditunda lagi:**

- Endpoint baru **khusus sempit** di SiLAKES: `POST /api/v1/integration/patients/{patient_id}/pembaruan-lapangan` — kontrak lengkap + aturan keselamatan per kategori field (geo vs kontak vs identitas) ada di Dokumen 1 §9. Ability token terpisah (`integration:write-field-data`), jangan pakai token read-only yang sudah ada.
- **Penting — jangan timpa field alamat resmi (KTP) yang sudah ada di SiLAKES.** Alamat KTP dan domisili aktual sering beda di lapangan (hal normal di Indonesia). Simpan sebagai tabel baru "alamat domisili terverifikasi" di SiLAKES, terpisah dari alamat KTP.
- **Keputusan terbaru: tidak ada auto-apply sama sekali.** Semua usulan dari KOPIPU — geo, kontak/alamat, maupun identitas — masuk `pending_review` dan baru berlaku setelah disetujui staf Labkesda lewat **halaman persetujuan** baru di SiLAKES. Ini kebijakan Dinkes: data pasien pemerintah wajib diverifikasi manusia, tidak dibedakan lagi per kategori risiko field seperti rancangan sebelumnya (geo pun sekarang ikut direview, bukan otomatis diterima).
- **Urutan kerja:** ini butuh kerja paralel di **kedua repo** — SiLAKES membangun endpoint penerima + halaman persetujuan (Dokumen 1 §9), KOPIPU membangun sisi pengirim di `VisitReportService` (mini-form konfirmasi lokasi + lengkapi data, sudah dirancang di atas) yang memanggil endpoint ini setelah laporan kunjungan tersimpan lokal. Keduanya bisa dikerjakan bersamaan, tidak saling blocking — KOPIPU tetap simpan `geo_status`/`latitude`/`longitude` secara lokal dulu meski panggilan ke SiLAKES gagal/tertunda (queue job dengan retry, bukan synchronous call yang bisa menggagalkan submit laporan kunjungan kader). KOPIPU tidak perlu polling status approval — begitu disetujui, sinkronisasi rutin (`updated_at` delta sync) otomatis menangkap perubahan di siklus berikutnya.
- **Rekomendasi implementasi tabel di sisi SiLAKES:** satu tabel `patient_field_updates` (bukan 2 tabel terpisah seperti draf sebelumnya) menampung semua kategori usulan dengan kolom `kategori` untuk pelaporan. Alamat hasil approve disimpan terpisah dari alamat KTP resmi — pertimbangkan model `Address` polymorphic bawaan `creasico/laravel-nusa` (`addresses`: `addressable_type/id`, `line`, `village_code`, dst.) sebagai basis, ditambah kolom `latitude`/`longitude`/`sumber` lewat migration terpisah — bukan mengubah `patients.alamat`/`kel_desa`/`kecamatan` yang lama.

| Service                     | Tanggung jawab                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SyncSilakesService`        | Dipanggil scheduler (tiap 48 jam — §4 Dokumen 1); pull data SiLAKES → map ke cache lokal → trigger `RiskClassificationService`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `RiskClassificationService` | Hitung level risiko dari `lab_results_cache` × `risk_thresholds`, simpan snapshot. **Wajib pakai hasil lab TERBARU per pasien berdasarkan `tanggal` (tanggal pemeriksaan medis), bukan urutan kedatangan sync** — delta sync tidak menjamin urutan kronologis (hasil lama bisa saja tersinkron belakangan). Query: `MAX(tanggal)` per `patient_id` di `lab_results_cache`, baru klasifikasi dari baris itu — jangan asumsikan baris yang baru disync = baris terbaru secara medis. **Kriteria (FINAL — murni AND boolean per parameter yang disebutkan, tidak ada pelonggaran "minimal N dari M"):**<br>`Berat` = `GDP>120 AND Creatinine>1.7 AND CholesterolTotal>200 AND Trigliserida>140 AND LDL>130 AND Urea>46` — keenamnya harus ADA HASILNYA dan semuanya melebihi. Kalau satu saja datanya belum ada, kondisi ini otomatis tidak terpenuhi (bukan Berat).<br>`Sedang` = `GDP>rujukan AND CholesterolTotal>rujukan AND Trigliserida>rujukan AND LDL>rujukan` (4 parameter, TIDAK butuh Creatinine/Urea) — keempatnya harus ada hasilnya dan melebihi, tidak terpenuhi kalau ada yang kosong.<br>`Ringan` = hanya GDP di atas nilai rujukan (parameter lain normal atau belum diperiksa).<br>**Default fallback**: pasien yang punya sebagian parameter Sedang tinggi tapi tidak lengkap 4 (mis. cuma GDP+Cholesterol, belum Trigliserida/LDL) → jatuh ke `Ringan`, bukan dibiarkan tidak terklasifikasi.<br>Hanya pasien `Berat` yang jadi kandidat kunjungan kader (lihat §12) |
| `VisitAssignmentService`    | Assign kader ke pasien, validasi ketersediaan kader, buat reminder                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `VisitValidationService`    | 7-layer validation — **rekomendasi Strategy Pattern per layer** (`GpsActiveCheck`, `GeofenceCheck`, `LiveCameraCheck`, `WatermarkGenerator`, `ExifValidator`, `FaceDetectionCheck`, `OfflineQueueHandler`) agar tiap layer independen, testable, dan bisa di-toggle sesuai kebijakan (Open/Closed Principle). **`FaceDetectionCheck` non-aktif secara default (feature-flag `config('kopipu.validation.face_detection_enabled')`)** — lihat §10                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| `NotificationService`       | Kirim reminder (push PWA / WA gateway) via queue, async                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |

## 4. Standardisasi API Response

```json
// Success
{
  "status": "success",
  "message": "Data berhasil diambil",
  "data": { }
}

// Error
{
  "status": "error",
  "message": "Pasien tidak ditemukan",
  "data": null,
  "errors": { "field": ["detail validasi"] }
}
```

Implementasi: helper `ApiResponse::success()/error()` + override `Exception Handler` (`render()`) supaya **semua** response — termasuk 422/401/500 — otomatis terbungkus format ini. Selaraskan dengan format SiLAKES supaya tim tidak belajar 2 pola berbeda.

## 5. Konfigurasi S3: Laravel atau Nuxt?

**Rekomendasi: Laravel yang menangani S3/MinIO, bukan Nuxt langsung.**

Alasan:

- Kredensial S3 tidak boleh terekspos ke client/browser.
- Validasi 7-layer (watermark, EXIF, geofencing) **harus** terjadi server-side sebelum foto dianggap sah — kalau upload langsung client → S3, validasi bisa dilewati.

Alur: Nuxt kirim foto (multipart) → Laravel validasi (GPS, EXIF, radius) → Laravel proses watermark → Laravel push ke S3/MinIO → simpan path + metadata di `visit_reports`.

**Infrastruktur MinIO aktual (sudah di-setup, production ready):**

- Endpoint API S3: `https://storage.kopipu-smart.labkesdasumenep.cloud` (domain `.cloud`, beda dari domain utama `.id` — sengaja dipisah karena ini trafik sistem-ke-sistem, bukan diakses manusia).
- Bucket: `kopipu`, subfolder per kategori: `pasien/`, `kader/`, `hasil-lab/`, `export/`, `article/`, `logo/`, `profile/`, `temp/`, `backup/`, `archive/` — foto laporan kunjungan (`visit_reports`) masuk kategori `pasien/` (dokumentasi kondisi rumah pasien), bukan `kader/`.
- IAM: akun aplikasi `kopipu-laravel` (least privilege — upload/download/delete/list saja, tidak bisa kelola bucket/user/policy), terpisah total dari root admin `kopipu-storage`. Kalau server Laravel diretas, penyerang cuma dapat akses file, bukan kontrol penuh storage.
- `.env`: `AWS_ACCESS_KEY_ID=kopipu-laravel`, `AWS_BUCKET=kopipu`, `AWS_ENDPOINT=https://storage.kopipu-smart.labkesdasumenep.cloud`, `AWS_USE_PATH_STYLE_ENDPOINT=true` (wajib true untuk MinIO, beda dari AWS S3 asli). **`FILESYSTEM_DISK` tetap `local`** (default framework, jangan diubah global) — pakai env var bernama khusus (`KOPIPU_VISIT_PHOTOS_DISK=s3`) untuk fitur yang benar-benar butuh S3, bukan mengubah default seluruh aplikasi; tidak semua yang Laravel simpan (log, cache file) perlu masuk S3.
- Struktur path objek: gabungkan taksonomi kategori dengan partisi tanggal — `pasien/visit-photos/YYYY/MM/DD/uuid.ext` (bukan kategori saja tanpa partisi, dan bukan partisi tanggal saja tanpa kategori).

Untuk offline-first (Layer 7): Nuxt simpan draft di IndexedDB, upload ke Laravel saat online (background sync) — tetap lewat Laravel, bukan direct-to-S3.

## 6. Autentikasi Nuxt ↔ Laravel

| Opsi                                       | Catatan                                                                          |
| ------------------------------------------ | -------------------------------------------------------------------------------- |
| Sanctum SPA (cookie-based)                 | Butuh domain/subdomain sama; CORS+cookie makin rumit untuk PWA mobile            |
| **Sanctum token (Bearer) + refresh token** | **Rekomendasi** — cocok untuk PWA lintas origin, jelas definisi mobile-app-token |
| Passport OAuth2                            | Overkill untuk 1 first-party app                                                 |
| JWT custom (tymon/jwt-auth)                | Stateless tapi revocation lebih rumit (perlu blacklist sendiri)                  |

Draft awal sudah menyebut "JWT + Sanctum + Device Binding + Refresh Token" — arah ini sudah tepat, dipertegas sebagai berikut:

- Access token short-lived (15–60 menit), refresh token long-lived (30 hari), **jangan simpan di localStorage** (rawan XSS) — pakai httpOnly cookie atau secure storage khusus PWA.
- **Device binding**: simpan `device_id`/fingerprint per refresh token agar token yang dicuri di device lain bisa dideteksi & di-revoke.
- **Login Google**: pakai `laravel/socialite` di backend. Nuxt redirect ke endpoint Laravel yang handle callback Google → Laravel keluarkan Sanctum token. **Jangan** implementasi OAuth Google langsung di Nuxt (client secret tidak boleh di frontend). **Perilaku (REVISI — bukan lagi find-or-create):** Google login **HANYA** berhasil untuk email yang **sudah punya baris `users`** (didaftarkan lebih dulu oleh super_admin/admin_puskesmas/PJ lewat `/api/v1/staff` atau `/api/v1/kader`) — **JANGAN auto-create user baru** kalau email tidak ditemukan. Kalau tidak ditemukan: redirect ke frontend dengan query error (mis. `?error=account_not_found`), pesan "Akun tidak ditemukan dengan email ini — hubungi PJ/Admin Puskesmas untuk didaftarkan." Kalau email ditemukan (user pre-registered, entah sudah/belum onboarding): login berhasil seperti biasa, middleware onboarding (§14) yang urus redirect ke `/onboarding` kalau perlu — bukan tanggung jawab `GoogleAuthController`.
- **Login email/password**: standar + rate limiting percobaan login (Fortify sebagai basis, atau custom sederhana bila butuh tampilan sendiri).

**Keputusan final SameSite cookie (domain prod sudah dikonfirmasi):** backend `api.kopipu-smart.labkesdasumenep.id` adalah subdomain dari frontend `kopipu-smart.labkesdasumenep.id` — keduanya berbagi **registrable domain/eTLD+1 yang sama** (`labkesdasumenep.id`), jadi secara teknis ini **same-site**, bukan cross-site. **`SameSite=Lax` sudah cukup dan benar untuk prod** — tidak perlu `SameSite=None` (yang mewajibkan HTTPS + punya risiko lebih longgar). Syarat teknis supaya ini benar-benar jalan:

- Cookie refresh token biarkan **host-only** (jangan set `Domain` attribute lebar ke parent) — karena cookie ini cuma perlu balik ke `api.kopipu-smart.labkesdasumenep.id`, bukan ke frontend.
- CORS di Laravel: `Access-Control-Allow-Origin` harus origin spesifik (`https://kopipu-smart.labkesdasumenep.id`), **bukan wildcard `*`** — tidak bisa pakai wildcard bersamaan dengan `Access-Control-Allow-Credentials: true`.
- Fetch di Nuxt wajib `credentials: 'include'`, kalau tidak cookie tidak pernah terkirim meski SameSite/CORS sudah benar.

**Checklist sebelum deploy prod (bukan sekarang, dicatat untuk nanti):**

- `GOOGLE_REDIRECT_URI` ganti ke `https://api.kopipu-smart.labkesdasumenep.id/api/v1/auth/google/callback`, `FRONTEND_URL` ke `https://kopipu-smart.labkesdasumenep.id`.
- Daftarkan redirect URI prod itu juga di Google Cloud Console (Authorized redirect URIs) — langkah manual di luar kode, gampang lupa dan gagalnya baru ketahuan saat login Google dicoba.

## 7. Role & Permission (Spatie Laravel-Permission)

| Role                            | Scope data                                                                                                                                                                                         |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `super_admin` (Dinkes/Labkesda) | Semua data + validasi final laporan kunjungan (§11)                                                                                                                                                |
| `admin_puskesmas`               | Filter by `puskesmas_id`; **monitoring saja** — tidak punya wewenang approve/validasi apa pun; bisa mendaftarkan `pj_prolanis` untuk puskesmasnya sendiri (lihat catatan `/api/v1/staff` di bawah) |
| `pj_prolanis`                   | Filter by `puskesmas_id` + kelola kader miliknya + menerima (acknowledge) laporan kunjungan dari kadernya (§11)                                                                                    |
| `kader`                         | Filter by assignment miliknya sendiri                                                                                                                                                              |

Catatan: PJ Prolanis bisa dual-role (`pj_prolanis` + `kader`). Gunakan **Policy class per resource** (bukan hanya middleware role) untuk cek scope data (`puskesmas_id`), karena role saja tidak cukup untuk isolasi data antar puskesmas.

**Akun `super_admin` — tidak perlu role baru, tapi wajib akun individual per orang.** "Akun Kadis" (lihat semua fitur, CRUD, monitoring) dan akun developer/admin sistem sama-sama cukup pakai role `super_admin` yang sudah ada — jangan buat role terpisah untuk ini. Yang wajib: **setiap orang (Kadis, Anda sendiri, siapa pun lain di level ini) punya akun login sendiri-sendiri**, jangan share 1 kredensial untuk banyak orang — karena `audit_logs`/`reviewed_by` (§9 Dokumen 1, halaman persetujuan SiLAKES) baru berguna untuk akuntabilitas kalau tiap aksi bisa dilacak ke individu yang benar, bukan ke "akun admin" generik.

**Profil kader — field mana wajib saat PJ mendaftarkan, mana boleh dilengkapi belakangan:** `no_hp` wajib saat registrasi (dipakai untuk kontak & aktivasi akun). `no_wa`, `alamat`, `gender`, `tgl_lahir` boleh kosong saat didaftarkan PJ, dilengkapi kader sendiri lewat halaman profil (self-service) — PJ yang mendaftarkan kader baru biasanya belum tentu tahu detail ini. Field-field ini data personalia kader (pekerja program), bukan data kesehatan pasien — jadi tidak butuh perlakuan khusus seperti hashing NIK, cukup kontrol akses standar (kader hanya bisa edit profilnya sendiri, PJ/admin_puskesmas bisa lihat tapi tidak wajib bisa edit semua field).

## 8. Penjadwalan & Reminder Kader

- `visit_assignments.scheduled_date` + `status`.
- Laravel Scheduler jalan harian → cek assignment H-1 dan hari-H → kirim reminder via Queue (Horizon sudah ada di stack) agar tidak blocking request.
- Channel: `NotificationService` pakai interface `ReminderChannel` (pola sama seperti `VisitValidationLayer`) — implementasi konkret saat ini pakai channel **`database`** bawaan Laravel (tabel `notifications`) sebagai default interim yang benar-benar berfungsi, bukan placeholder. Push notification PWA (Web Push API) jadi task terpisah nanti setelah infrastruktur frontend (VAPID, service worker registration) siap di Tahap 3 — arsitektur interface sudah siap menampungnya tanpa refactor. WA gateway (mis. Fonnte/WATI) tetap opsi tambahan bila ada anggaran, channel baru lewat interface yang sama — **catatan kepatuhan**: data pasien/kader yang dikirim ke gateway WA pihak ketiga perlu dicek klausul data processing agreement-nya.

## 9. Observability

- Laravel Pulse (performance monitoring — sudah direncanakan di stack).
- Laravel Telescope (dev/staging only).
- Logging terstruktur (JSON) untuk audit & debugging.

## 10. Catatan Kepatuhan Tambahan

- **Layer 6 (Face Detection) — keputusan: non-aktif secara default di v1.** 6 layer lain (GPS, geofencing, live camera, watermark, EXIF, offline queue) sudah memberi anti-fraud yang kuat; face detection menambah kompleksitas (model deteksi, beban PWA di HP low-end kader) untuk kenaikan keamanan yang marginal.
    - Implementasi tetap dibuat sebagai strategy terpisah (`FaceDetectionCheck`) di belakang feature-flag `config('kopipu.validation.face_detection_enabled')` supaya bisa diaktifkan kapan saja tanpa refactor.
    - Kalau nanti diaktifkan: gunakan deteksi ringan di sisi browser (bukan model custom yang di-training/host sendiri), hanya boolean _keberadaan_ wajah dalam frame.
    - **Jangan pernah** simpan face embedding/template biometrik permanen atau lakukan face matching/recognition identitas — itu masuk kategori data pribadi spesifik yang butuh consent eksplisit terpisah menurut UU PDP No. 27/2022.

## 11. Alur Review & Validasi Laporan Kunjungan (dari draft resmi, diperjelas)

Dua tahap terpisah setelah kader submit laporan (di luar 7-layer validation yang sudah menjamin keaslian kunjungan itu sendiri):

1. **PJ Prolanis menerima laporan** (`pj_reviewed_by`/`pj_reviewed_at` di `visit_reports`) — pengakuan operasional bahwa laporan dari kadernya sudah masuk dan diketahui. Endpoint: `PATCH /api/v1/visit-reports/{id}/accept` (pj_prolanis, hanya laporan dari kader miliknya sendiri).
2. **Super Admin (Labkesda/Dinkes) validasi final** (`validation_status`: `pending`→`valid`/`invalid`, `validated_by`/`validated_at`/`validation_note`) — penentu apakah laporan itu sah. Endpoint: `PATCH /api/v1/validasi-laporan/{id}` (super_admin only), body `{is_valid: bool, note?: string}`. Super_admin bisa validasi kapan pun, tidak wajib menunggu PJ menerima dulu (super_admin punya kewenangan tertinggi).

**Admin Puskesmas tidak terlibat di alur ini sama sekali** — cuma bisa melihat status `pj_reviewed_at`/`validation_status` sebagai bagian monitoring, tidak ada aksi approve.

**Aturan keras soal insentif — WAJIB dipatuhi di seluruh UI:** status `valid`/`invalid` dipakai Labkesda untuk proses insentif kader **di luar sistem ini sepenuhnya**. Aplikasi (backend maupun frontend) **tidak boleh menyebut, menampilkan, atau mengimplikasikan** kata "insentif", "honor", "bayaran", atau sejenisnya di mana pun — label yang benar cuma "Validasi Laporan", status "Valid"/"Tidak Valid" (bukan "Layak Insentif"/"Disetujui untuk Honor" atau sejenisnya). Ini bukan preferensi kosmetik, ini batasan yang harus diikuti persis.

**Kalau `validation_status=invalid`:** `visit_assignments.status` kembali ke `pending` (assignment dibuka lagi, bukan status baru — "perlu diulang" cukup dideteksi dari relasi ke laporan lama berstatus `invalid`). Laporan lama TIDAK dihapus (jejak audit). Kader dapat notifikasi otomatis (`NotificationService`) berisi `validation_note` supaya tahu alasan dan bisa kunjungan ulang dengan benar.

## 12. Alur Penugasan Kunjungan (desain final)

**Prinsip:** kader dapat daftar tugas TETAP dan spesifik (bukan browse-sendiri dari wilayah) — supaya progress terukur pasti (X dari N tugas selesai) dan kader (mayoritas lansia) tidak perlu memutuskan sendiri siapa yang dikunjungi. PJ yang memutuskan, tapi prosesnya dipermudah lewat filter wilayah + bulk-select, bukan pilih pasien satu-satu manual.

**Alur:** PJ buka `/dashboard/kunjungan` → filter pasien kandidat (level risiko **Berat** saja, scope puskesmasnya, opsional filter per desa/kecamatan) → daftar tampil dengan checkbox, ada opsi "Pilih Semua yang Belum Ditugaskan" → PJ pilih kader tujuan + centang pasien (bisa banyak sekaligus) → modal konfirmasi menampilkan daftar nama yang akan jadi target → submit → sistem buat banyak `visit_assignments` sekaligus (1 per pasien terpilih, kader sama).

**Endpoint baru:** `POST /api/v1/visit-assignments/bulk` — body `{kader_id, patient_ids: [], scheduled_date, priority}` (satu `scheduled_date` dan `priority` berlaku untuk seluruh pasien dalam batch itu, PJ pilih sekali di modal konfirmasi — bukan per-pasien), validasi tetap sama seperti assignment tunggal (wilayah_status resolved/kecamatan_fallback, kader aktif+sepuskesmas, no duplicate assignment aktif) diterapkan per pasien — kalau ada yang gagal validasi, laporkan mana yang gagal dan alasannya, yang lolos tetap dibuat (partial success, bukan all-or-nothing).

## 14. Onboarding First-Login

**Kolom baru di `users`:** `onboarding_completed_at` (nullable timestamp), `tos_accepted_at` (nullable timestamp) — terpisah (waktu akseptasi ToS punya nilai legal sendiri, beda dari "selesai onboarding").

**Endpoint baru:** `POST /api/v1/auth/onboarding/complete` — isi `onboarding_completed_at`+`tos_accepted_at` = now(). Kalau kader, boleh sekalian terima payload profil (no_wa/alamat/gender/tgl_lahir) di request yang sama — reuse `KaderService` yang sudah ada untuk itu, bukan endpoint terpisah.

**Middleware baru** (pola identik `EnsurePasswordChanged`, §10) — kalau `onboarding_completed_at IS NULL`, blokir akses ke endpoint lain (kecuali `/auth/me`, `/auth/logout`, `/auth/onboarding/complete`), balas 403 `data.code=ONBOARDING_REQUIRED`.

**Yang ditampilkan saat onboarding (bukan diinput bebas):** puskesmas dan PJ Prolanis pasien sudah ditentukan saat registrasi — halaman onboarding cuma MENAMPILKAN ini untuk dikonfirmasi ("Anda terdaftar di Puskesmas X, di bawah PJ [nama]"), bukan field pilihan. Kalau ternyata salah, itu perlu dibetulkan oleh yang mendaftarkan (PJ/admin), bukan diubah sendiri oleh user — supaya integritas data organisasi terjaga.

**`GET /api/v1/dashboard/summary` perlu tambahan field:**

- `kader_aktif_count` — jumlah kader `status_aktif=true` ter-scope
- `tingkat_kepatuhan` — didefinisikan sebagai (kunjungan `completed` ÷ total kunjungan ditugaskan) × 100, ter-scope role
- `aktivitas_hari_ini` — per kader: nama, target hari ini, jumlah selesai, waktu update terakhir
- `risiko_per_kecamatan` — jumlah pasien per level risiko, dikelompokkan per kecamatan (untuk peta GIS frontend — peta/poligon wilayah SUDAH ADA di frontend, backend cukup kirim data agregat untuk di-mapping berdasarkan nama/kode kecamatan, JANGAN kirim/generate ulang data poligon)

**Fitur baru: Pengumuman Sistem** (dikonfirmasi kebutuhan nyata, bukan placeholder) — tabel `system_announcements` (`title`, `description`, `type` [info/success/warning], `posted_by`, `created_at`). Endpoint: `GET /api/v1/announcements` (semua role login), `POST /api/v1/announcements` (super_admin only).

**Koreksi kewenangan `POST /api/v1/staff`:** draft resmi sebut Admin Puskesmas berwenang "Kelola PJ Prolanis" — endpoint ini yang sudah dibangun (Prompt 11d) keliru membatasi cuma `super_admin`. Perbaikan: `admin_puskesmas` juga boleh daftarkan `pj_prolanis`, dipaksa ke `puskesmas_id` miliknya sendiri (pola sama seperti `KaderService` memaksa `puskesmas_id`, jangan percaya input klien). `admin_puskesmas` **tidak boleh** daftarkan sesama `admin_puskesmas` — itu tetap wewenang `super_admin`.

**"Export data" (PJ Prolanis, dari draft resmi):** belum dibangun, sengaja ditunda sampai fitur inti selesai (keputusan user).

## 15. Data Instansi (Puskesmas) — Kolom Kontak & Seeder Lengkap

Frontend pakai label **"Data Instansi"**, tabel backend tetap `puskesmas` (lihat §2a soal ini). Seeder baru: 31 baris diturunkan dari tabel `kecamatan` yang sudah di-seed (1 puskesmas per kecamatan, nama sama, kecuali 4 kecamatan dengan 2 puskesmas — daftar pengecualian manual, lihat §2a). Kolom kontak baru (§skema di atas) dibiarkan `NULL` saat seed — diisi belakangan lewat endpoint update oleh admin_puskesmas/super_admin, BUKAN bagian dari data seed.

**Endpoint:**

- `GET /api/v1/puskesmas` — list, semua role login, tanpa scope (semua orang boleh lihat daftar instansi)
- `GET /api/v1/puskesmas/{id}` — detail
- `PATCH /api/v1/puskesmas/{id}` — admin_puskesmas (cuma puskesmasnya sendiri) atau super_admin (bebas). Field yang bisa diubah: `alamat`, `no_telp`, `no_wa`, `latitude`, `longitude`, `deskripsi` — TIDAK termasuk `nama`/`kode_kemendagri` (identitas resmi, dikunci)

Tidak ada `POST`/`DELETE` — penambahan/penutupan puskesmas itu peristiwa organisasi langka, tetap lewat migration/seeder terkontrol, bukan tombol UI.

## 16. Kunjungan Berombongan — Rencana (Assignment) vs Aktual (Laporan)

Realita lapangan: PJ sering menugaskan beberapa kader sekaligus ke 1 pasien yang sama. Model `visit_assignments` (kolom `kader_id` = pemilik/penanggung jawab tugas) **tidak berubah** — supaya progress/tingkat kepatuhan tetap terhitung bersih per kader primer, tidak redundan. Kader tambahan dicatat terpisah, di 2 titik beda tujuan (rencana vs aktual), bukan duplikasi:

**1. Rencana (saat PJ menugaskan) — tabel baru `visit_assignment_companions`** (`assignment_id` FK, `kader_id` FK, `created_at`). Diisi lewat `POST /api/v1/visit-assignments/bulk` yang diperluas body-nya: `{kader_id (primer), companion_kader_ids: [] (opsional), patient_ids: [], scheduled_date, priority}` — validasi companion sama seperti kader primer (aktif, sepuskesmas). Endpoint yang sama dipakai baik untuk bulk-assign biasa (companion kosong) maupun kunjungan berombongan (companion diisi) — tidak perlu endpoint terpisah.

**2. Aktual (saat kader submit laporan) — tabel `visit_report_attendees`** (`visit_report_id` FK, `kader_id` FK, `created_at`) — dari desain sebelumnya, TETAP dipakai, tapi sekarang **pre-filled dari `visit_assignment_companions`** milik assignment terkait, bukan diisi dari nol oleh kader. Kader primer (pengirim laporan) tinggal konfirmasi atau koreksi (ada yang batal ikut / ada tambahan tak terencana) sebelum submit.

**`GET /api/v1/visit-assignments` untuk kader pendamping** — assignment yang mereka dampingi harus ikut muncul di daftar tugas mereka sendiri (`/app/tugas`), ditandai field pembeda (mis. `role_in_assignment: primary|companion`) supaya frontend bisa beri label beda ("Anda mendampingi [nama primer]").

**Tidak menyentuh insentif/honor** — murni catatan rencana & kehadiran faktual, sama seperti sebelumnya.

**Notifikasi email saat finalisasi penugasan:** setelah `POST /api/v1/visit-assignments/bulk` atau `/group` berhasil buat assignment, kirim **1 email ringkas per kader** (primer maupun pendamping yang kena batch itu) — bukan 1 email per pasien (hindari spam kalau PJ tugaskan banyak pasien sekaligus). Isi email **minimal, tidak sebut nama/data pasien** (data kesehatan tidak masuk kanal email yang kurang terjamin dibanding aplikasi): "Anda mendapat N tugas kunjungan baru terjadwal [tanggal] — buka aplikasi untuk detail." + link ke `/app/tugas`. Mailable baru (`VisitAssignedMail` atau serupa), `ShouldQueue` (pola sama seperti `AccountActivationMail` — jangan blocking request PJ nunggu SMTP).

## 17. Menu Sidebar Nyata, Halaman Detail, Profil & Pengaturan

### Struktur menu (ganti dummy `href="#"`)

```
OPERASIONAL
- Dashboard (/dashboard) — semua staf
- Data Pasien (/dashboard/pasien) — semua staf (scoped)
- Kunjungan (/dashboard/kunjungan) — semua staf (scoped, aksi role-gated di dalam halaman)

MANAJEMEN
- Manajemen Kader (/dashboard/kader) — PJ/admin_puskesmas/super_admin
- Manajemen Staf (/dashboard/staf) — admin_puskesmas/super_admin
- Data Instansi (/dashboard/instansi — BARU, backend §15 sudah ada, halaman belum pernah dibangun) — lihat semua staf, edit admin_puskesmas(sendiri)/super_admin

SISTEM (super_admin)
- Pengaturan — placeholder untuk risk_thresholds dkk (belum ada endpoint, backlog terpisah — jangan janji fitur di menu sebelum ada isinya)
```

**Dihapus dari menu**: "Jadwal Kader" (data sama persis dengan Kunjungan, jangan duplikasi — kalau perlu tampilan kalender, itu view berbeda di halaman yang SAMA, bukan menu terpisah), "Laporan Kinerja" (mapping ke "export data" PJ Prolanis yang sudah sengaja ditunda — jangan tampilkan menu untuk fitur yang belum ada).

**Pengumuman**: tidak perlu menu sendiri — sudah ada panel di `/dashboard` (Fase A3), form posting (super_admin) taruh di situ juga, bukan halaman terpisah.

### "Lihat Detail" — reuse halaman yang sudah ada, JANGAN bikin halaman baru

| Card                | Link ke                                              | Catatan                                                                                          |
| ------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Total Pasien Aktif  | `/dashboard/pasien`                                  | tanpa filter                                                                                     |
| Risiko Berat/Sedang | `/dashboard/pasien?risk_level=Berat` (atau `Sedang`) | `/dashboard/pasien` sudah dukung filter ini sejak Prompt 11a                                     |
| Kunjungan Selesai   | `/dashboard/kunjungan?status=completed`              |                                                                                                  |
| Kader Aktif         | `/dashboard/kader`                                   |                                                                                                  |
| Tingkat Kepatuhan   | `/dashboard/kunjungan` (tanpa filter)                | angka ini rasio, bukan daftar — lihat semua assignment paling masuk akal untuk menilai kepatuhan |

### Peta desa — tambah breakdown risiko (paralel `risiko_per_kecamatan`)

`GET /api/v1/dashboard/summary` tambah `risiko_per_desa` — sama persis pola `risiko_per_kecamatan` (§13) tapi dikelompokkan per `desa_id`, HANYA pasien `wilayah_status=resolved` (bukan `kecamatan_fallback` — level desa butuh presisi lebih tinggi). Ekspektasi wajar: cakupannya kecil (355 pasien resolved saat ini) — banyak desa akan tetap kosong sampai `kopipu:import-desa-puskesmas` dijalankan (lihat temuan gap wilayah sebelumnya).

### Profil Saya & Pengaturan (semua role)

**Kolom baru di `users`:** `avatar_path` (nullable), `email_notifications_enabled` (boolean, default `true`).

**Endpoint baru:**

- `POST /api/v1/auth/profile/avatar` — upload foto profil (multipart), simpan ke S3/MinIO (reuse config yang sudah ada), update `avatar_path`.
- `PATCH /api/v1/auth/profile` — update `email_notifications_enabled` (dan field umum lain kalau ada nanti). **Bukan** pengganti `PATCH /api/v1/kader/profile` yang sudah ada (itu tetap khusus field kader: no_wa/alamat/gender/tgl_lahir) — endpoint baru ini untuk field yang berlaku semua role.

**Tautkan Google** — reuse endpoint yang SUDAH ADA (`/auth/google/link/redirect`, `/auth/google/unlink`, Prompt 11f) — cukup disurfacekan di halaman Pengaturan yang baru, verifikasi kepemilikan email otomatis terjadi lewat OAuth-nya sendiri, tidak butuh mekanisme verifikasi tambahan.

**Ubah Password** — reuse endpoint yang SUDAH ADA (`/auth/change-password`, Prompt 4c) — surfacekan di halaman yang sama.

**Aturan `email_notifications_enabled`:** berlaku untuk notifikasi non-kritis (reminder kunjungan, `VisitAssignedMail`). **TIDAK berlaku** untuk email keamanan akun (aktivasi, reset password) — itu selalu terkirim apa pun preferensinya, karena itu bukan "notifikasi", itu bagian dari alur keamanan akun itu sendiri.

---

_Lanjut ke Dokumen 3: UI/UX & Frontend Nuxt KOPIPU Smart._
