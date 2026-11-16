# Blueprint 16: Integrasi Erat SiLAKES dan PRODULI

> Status: **DISETUJUI** (2026-11-16) dengan keputusan di §9. Fase 0 selesai; perbaikan form usulan + scan KTP (§10) dikerjakan lebih dulu karena mendesak.
> Salinan identik: `SiLAKES/api-administrasi-labkesda/docs/planning/16-blueprint-integrasi-erat-silakes-produli.md`.
> Dokumen ini menggantikan sebagian aturan di dokumen 01 dan 04 (lihat §2). Kalau bertentangan, dokumen ini yang berlaku setelah disetujui.

## 0. Permintaan dan keputusan yang sudah diambil

Permintaan:

1. Kunci hash NIK sama di SiLAKES dan PRODULI supaya bisa saling verifikasi, dibackup ke file txt di laptop.
2. Permintaan puskesmas: begitu usulan perubahan data pasien di-approve di SiLAKES, data di PRODULI langsung berubah saat itu juga, tanpa menunggu sinkron harian.
3. Riwayat sinkron per pasien ikut tercatat dan terbarui.
4. `/dashboard/pasien` menampilkan waktu sinkron, dan bisa melihat data hasil sinkron tanggal lalu.
5. Pasien otomatis menjadi pasien tetap milik puskesmasnya.
6. Riwayat sinkron harian dipakai menilai pasien yang membaik, menjadi poin puskesmas dan masuk peringkat kinerja terbaik.
7. PRODULI adalah turunan SiLAKES: integrasi harus jauh lebih dekat, pembatasan data pasien dikurangi.

Keputusan (dijawab pemilik sistem 2026-11-16):

| Topik | Keputusan |
|---|---|
| NIK | Tetap kirim **NIK asli + `nik_hash`**. `nik_hash` jadi kunci verifikasi silang. |
| Kunci hash | **Pakai kunci yang aktif**, tidak dirotasi. Sudah dibackup (§Fase 0). |
| Poin kinerja | **Semua perbaikan dapat poin + bonus bila didahului kunjungan tervalidasi.** |
| antislop | Mode DURING untuk seluruh pekerjaan ini. |

## 1. Kondisi sekarang (diukur di production 2026-11-16)

| Temuan | Data | Dampak |
|---|---|---|
| Perubahan yang di-approve baru sampai ke PRODULI di sinkron harian berikutnya (02:00) | `produli:sync-silakes` sekali sehari | Puskesmas menunggu sampai 24 jam |
| **Bug:** usulan alamat, kel/desa, kecamatan, RT/RW dan titik GPS dari PRODULI disimpan SiLAKES ke `patient_domiciles`. Tabel itu tidak ikut endpoint `patients` dan tidak mengubah `patients.updated_at` | `PatientFieldUpdateApplier::terapkan()` | Usulan domisili yang sudah disetujui **tidak pernah kembali** ke PRODULI |
| Banyak kolom pasien tidak disinkron: `tempat_lahir`, `golongan_darah`, `agama`, `status_perkawinan`, `pekerjaan`, `is_bpjs`, `fktp`, `email` | Endpoint `patients` hanya mengirim 18 kolom | Form usulan PRODULI tidak tahu nilai lama, kader mengisi dari nol |
| Puskesmas pemilik dihitung ulang setiap sinkron (kecuali override manual) | 4.231 lewat pengirim, 2.302 lewat kecamatan, 178 lewat desa, 42 pengirim perorangan, 4 tidak terpetakan, 46 tanpa puskesmas | Pasien bisa berpindah puskesmas diam-diam, poin ikut berpindah |
| Tidak ada riwayat harian. `risk_classifications` hanya menyimpan baris saat level berubah | 12.034 baris, 6.755 terbaru | Tidak bisa melihat "data per tanggal X" |
| Peringkat kinerja puskesmas kosong | 5.279 transisi risiko, **0** memenuhi syarat (wajib kunjungan tervalidasi) | Halaman kinerja tidak berguna |
| Kegagalan sinkron tidak diberitahukan ke siapa pun | Gagal HTTP 401 dari 21 Okt sampai 13 Nov (24 hari) | Data PRODULI basi tanpa ada yang tahu |
| Pasien Prolanis yang ditarik hanya yang punya hasil lab Prolanis sejak 2026-01-01 | 6.757 dari 10.776 pasien Prolanis SiLAKES | Daftar Prolanis puskesmas tidak lengkap |

## 2. Prinsip baru: PRODULI turunan SiLAKES

| Aturan lama (dok. 01/04, CLAUDE.md) | Aturan baru |
|---|---|
| SiLAKES read-only, tidak "tahu" PRODULI | SiLAKES **mengirim event** ke PRODULI setiap ada perubahan data pasien Prolanis |
| Satu-satunya jalur tulis: `pembaruan-lapangan` | Tetap. Arah PRODULI ke SiLAKES tetap hanya lewat usulan (`pembaruan-lapangan`, `prolanis-deliveries`) |
| Data pasien dikirim seminimal mungkin | Semua kolom identitas, kontak, dan domisili dikirim. Pengecualian: berkas `foto_ktp` (lihat §9) |
| NIK sebaiknya hanya hash | NIK asli + `nik_hash` dengan kunci bersama |
| Webhook "belum dibangun, nanti" | Dibangun sekarang (§3) |

Yang **tetap dipertahankan**, karena ini pengaman dan bukan pembatasan:

- Perubahan data pasien tetap lewat persetujuan staf Labkesda (atau sakelar auto approve yang sudah ada). Ini kebijakan Dinkes.
- Semua panggilan antar-sistem memakai token + tanda tangan HMAC. Tidak ada akses database lintas sistem.
- Akses data di PRODULI tetap dibatasi per role dan per puskesmas (Policy yang sudah ada).

## 3. Alur baru

### 3a. Event real-time SiLAKES ke PRODULI (baru)

```
Staf SiLAKES klik Setujui / auto approve / edit pasien Prolanis
        |
        v
SiLAKES: NotifyProduliPatientChangedJob   (antrian, setelah commit,
        |                                  digabung per pasien 5 detik)
        |  POST {PRODULI}/api/v1/integration/silakes/events
        |  body: {event_id, event, patient_id, field_update_ids, occurred_at}
        |  header: X-Signature / X-Timestamp (HMAC, PRODULI_INTEGRATION_SECRET)
        v
PRODULI: verifikasi HMAC -> simpan silakes_inbound_events (idempoten, event_id unik)
        |  balas 202 segera
        v
PRODULI: RefreshPatientFromSilakesJob
        |  GET /integration/patients/{id}              (baru, 1 pasien lengkap + domisili)
        |  GET /integration/patients/{id}/lab-results  (baru)
        |  PatientSyncMapper (kode pemetaan yang SAMA dengan sinkron harian)
        |  catat patient_sync_histories (field apa berubah, lama -> baru)
        |  klasifikasi ulang risiko bila hasil lab berubah
        v
WebSocket produli-wss: event patient.updated  -> tabel & detail pasien refresh sendiri
Notifikasi ke pengusul: "Usulan perubahan data <nama> disetujui Labkesda"
```

Target: data berubah di PRODULI **kurang dari 1 menit** setelah staf klik Setujui.

Event yang dikirim SiLAKES:

| Event | Pemicu di SiLAKES |
|---|---|
| `patient.updated` | Usulan disetujui (manual, Setujui Semua, auto approve) lewat `PatientFieldUpdateApplier`; staf edit pasien Prolanis (`PatientController::update`, input massal) |
| `field_update.rejected` | Usulan ditolak (supaya status di PRODULI ikut berubah) |
| `patient.merged` | Dua pasien digabung (`PatientMergeDialog`). Payload `{from_patient_id, to_patient_id}`, PRODULI memindahkan riwayat ke pasien tujuan |
| `lab_result.finalized` | Hasil lab Prolanis menjadi `completed` + `approved` (opsional, §9 no. 5) |

Kenapa event hanya membawa `patient_id`, bukan isi data: PRODULI selalu menarik data dari endpoint resmi, jadi hanya ada satu jalur pemetaan. Event palsu tidak bisa menyuntikkan data, dan event yang hilang tetap tertangkap sinkron harian.

Ketahanan: retry 1 menit, 5 menit, 15 menit, 1 jam. Kolom baru `patient_field_updates.produli_notified_at` di SiLAKES mencatat kapan PRODULI menerima event, jadi staf bisa melihat statusnya.

### 3b. Sinkron harian tetap jalan

Jam 02:00 tetap menjalankan sinkron delta sebagai jaring pengaman, lalu membuat **snapshot harian** (§4c).

### 3c. Peringatan sinkron gagal (baru)

- Setiap sinkron gagal: notifikasi push + email ke semua `super_admin`, berisi pesan error.
- Banner merah di dashboard super_admin bila sinkron sukses terakhir lebih dari 26 jam lalu.

## 4. Perubahan data

### 4a. SiLAKES

1. Endpoint `GET /integration/patients` menambah kolom: `tempat_lahir`, `golongan_darah`, `agama`, `status_perkawinan`, `pekerjaan`, `is_bpjs`, `fktp`, `email`, dan objek `domisili {alamat, kel_desa, kecamatan, rt_rw, latitude, longitude, sumber, updated_at}`. Semua tambahan, tidak ada kolom lama yang berubah.
2. **Perbaikan bug domisili:** `PatientFieldUpdateApplier` memanggil `$patient->touch()` setiap menyimpan `patient_domiciles`, supaya sinkron delta ikut menangkap.
3. Endpoint baru `GET /integration/patients/{id}` dan `GET /integration/patients/{id}/lab-results` (ability baca yang sudah ada).
4. Konfigurasi baru: `PRODULI_BASE_URL` (alamat API PRODULI untuk event). Secret HMAC memakai `PRODULI_INTEGRATION_SECRET` yang sudah sama di kedua server.
5. Kolom baru `patient_field_updates.produli_notified_at`.
6. Halaman `/patient/field-updates` menampilkan status kirim ke PRODULI per usulan yang disetujui (Terkirim / Gagal, kirim ulang).

### 4b. PRODULI: tabel `patients_cache`

Kolom baru:

| Kolom | Isi |
|---|---|
| `tempat_lahir`, `golongan_darah`, `agama`, `status_perkawinan`, `pekerjaan`, `is_bpjs`, `fktp`, `email` | Salinan dari SiLAKES |
| `domisili_alamat`, `domisili_kel_desa`, `domisili_kecamatan`, `domisili_rt_rw`, `domisili_updated_at` | Domisili terverifikasi dari SiLAKES |
| `silakes_updated_at` | Waktu data berubah di SiLAKES (selama ini disimpan di `last_synced_at`, namanya menyesatkan) |
| `refreshed_at`, `refreshed_via` | Kapan PRODULI terakhir menarik data pasien ini, dan lewat jalur apa: `harian`, `realtime`, `manual`. **Ini yang tampil sebagai "Sinkron terakhir"** |
| `puskesmas_locked_at` | Waktu kepemilikan puskesmas dikunci (§4d) |

Bila domisili ada, domisili dipakai untuk resolusi desa dan titik peta (`geo_source = silakes_domisili`), alamat KTP tetap disimpan apa adanya.

### 4c. PRODULI: tabel baru

**`silakes_inbound_events`**: `event_id` (unik), `event`, `external_patient_id`, `payload`, `received_at`, `processed_at`, `status`, `error`. Untuk idempotensi dan audit.

**`patient_sync_histories`** (riwayat sinkron per pasien): `patients_cache_id`, `synced_at`, `sumber` (`harian`/`realtime`/`manual`), `silakes_event`, `changed_fields` (JSON `{field: {lama, baru}}`), `field_update_ids`, `risk_level_sebelum`, `risk_level_sesudah`. Satu baris hanya ditulis bila ada yang berubah, jadi tidak ada baris kosong tiap hari.

**`patient_daily_snapshots`** (data per tanggal): `snapshot_date`, `patients_cache_id`, `puskesmas_id`, `kecamatan_id`, `desa_id`, `risk_level`, `risk_classification_id`, `early_detection_flag`, `tanggal_lab_terakhir`. Unik `(snapshot_date, patients_cache_id)`, indeks `(snapshot_date, puskesmas_id, risk_level)`.

- Dibuat setiap hari setelah sinkron 02:00. Satu baris per pasien per hari.
- Perkiraan ukuran: 6.757 sampai 10.776 baris per hari, sekitar 2,5 sampai 3,9 juta baris per tahun. Masih ringan untuk MySQL dengan indeks di atas.
- Backfill: snapshot mundur dibangun dari riwayat `risk_classifications` (sudah tersimpan per perubahan sejak Agustus 2026). Catatan jujur: hasil backfill mencerminkan riwayat level risiko, bukan isi kolom identitas di masa lalu.

### 4d. Kepemilikan puskesmas tetap

- Begitu pasien pertama kali mendapat puskesmas, `puskesmas_locked_at` diisi. Sinkron berikutnya tidak menghitung ulang.
- Pindah puskesmas hanya lewat tombol override yang sudah ada (`PATCH /patients/{id}/override-puskesmas`), dan tercatat di `patient_sync_histories`.
- Pasien yang belum punya puskesmas (46 + 4 tidak terpetakan) tetap dihitung ulang setiap sinkron sampai dapat.
- Saat deploy: semua pasien yang sudah punya puskesmas langsung dikunci.

### 4e. Poin dan peringkat kinerja

Aturan baru (angka masih usulan, lihat §9):

| Transisi | Poin |
|---|---|
| Membaik 1 level (mis. Berat ke Sedang) | +10 per level |
| Memburuk 1 level | -10 per level |
| Tetap Tidak Berisiko | +2 (retensi, aturan lama) |
| **Bonus**: ada kunjungan tervalidasi di antara dua pemeriksaan | +5 di atas poin perbaikan |

- Yang dihitung: **semua** transisi yang terjadi karena **pemeriksaan lab baru** (tanggal pemeriksaan naik). Transisi yang muncul hanya karena hitung ulang aturan (`produli:reclassify-risk` tanpa lab baru) tidak dihitung, supaya perubahan ambang tidak membuat poin palsu.
- Poin masuk ke puskesmas **pemilik pasien saat transisi terjadi** (dibaca dari snapshot), bukan pemilik sekarang.
- Filter periode memakai tanggal pemeriksaan, bukan `calculated_at` (sekarang semua backfill akan jatuh di tanggal yang sama).
- 5.279 transisi lama dihitung ulang dengan aturan baru, jadi peringkat langsung terisi.
- Rumus skor akhir lama (improvement 50%, risk reduction 30%, stability 20%) tetap, ditambah kolom total poin.

## 5. Tampilan PRODULI

Design read: dashboard internal untuk staf puskesmas dan Dinkes, padat data, mengikuti token di `produli-fe/docs/planning/07-design-tokens-dan-koreksi-referensi.md`. Dial ENERGY 1 / RHYTHM 1 / MOTION 1. Halaman kader tidak berubah selain notifikasi.

**`/dashboard/pasien`**

- Kolom **Sinkron terakhir**: tanggal dan jam, plus label sumber (Harian / Real-time / Manual).
- Kontrol **Data per tanggal**: default "Terkini". Memilih tanggal lalu menampilkan isi snapshot tanggal itu (level risiko dan puskesmas saat itu), dengan banner "Menampilkan data per 10 Nov 2026" dan tombol kembali ke data terkini.
- Kontrol **Bandingkan dengan tanggal**: menambah kolom Perubahan (Membaik / Memburuk / Tetap, contoh "Berat ke Sedang") dan filter cepat "Hanya yang membaik".
- Baris yang diperbarui dari SiLAKES ikut berubah otomatis lewat WebSocket, dengan notifikasi kecil "Data <nama> diperbarui dari SiLAKES".
- State kosong, memuat, dan error untuk setiap mode, termasuk tanggal sebelum snapshot pertama.

**`/dashboard/pasien/[id]`**: tab baru **Riwayat Sinkron** (timeline: waktu, sumber, kolom yang berubah lama ke baru, usulan terkait, perubahan level risiko). Status usulan berubah langsung saat disetujui atau ditolak.

**`/dashboard` (admin puskesmas, PJ Prolanis)**: kartu pasien membaik sejak kemarin, 7 hari, dan 30 hari; poin dan peringkat puskesmas pada periode berjalan.

**`/dashboard/kinerja-puskesmas`**: pilih periode, tabel peringkat dengan rincian poin (perbaikan, bonus kunjungan, retensi, perburukan), klik puskesmas untuk melihat daftar pasien yang membaik.

## 6. Fase pengerjaan

| Fase | Isi | Repo |
|---|---|---|
| 0 | Backup kunci hash NIK. **Selesai** (§7) | keduanya |
| 1 | Kontrak data SiLAKES: kolom tambahan, `touch()` domisili, 2 endpoint satu pasien | SiLAKES |
| 2 | Kolom baru `patients_cache`, `PatientSyncMapper`, `patient_sync_histories`, kunci kepemilikan, peringatan sinkron gagal | PRODULI BE |
| 3 | Event real-time: job SiLAKES, endpoint dan job PRODULI, WebSocket, notifikasi pengusul, status kirim di SiLAKES | keduanya |
| 4 | Snapshot harian + backfill + tampilan pasien (sinkron terakhir, per tanggal, bandingkan, riwayat sinkron) | PRODULI BE + FE |
| 5 | Aturan poin baru + hitung ulang transisi + tampilan kinerja | PRODULI BE + FE |
| 6 | Perbarui CLAUDE.md kedua repo dan dokumen 01/04 sesuai §2 | keduanya |

Urutan deploy: SiLAKES dulu di setiap fase (semua perubahan SiLAKES bersifat tambahan, PRODULI lama tetap jalan), lalu PRODULI. Setiap fase bisa dirilis sendiri dan diuji dengan test PHPUnit/Vitest yang sudah ada di masing-masing repo.

## 7. Fase 0: backup kunci hash NIK (selesai 2026-11-16)

- Kunci `PRODULI_NIK_HASH_SECRET` di production PRODULI dan SiLAKES dicocokkan lewat sidik jari SHA-256: **sama**.
- Disalin ke `produli-be/nik-hash-secret.backup.txt` dan `SiLAKES/api-administrasi-labkesda/nik-hash-secret.backup.txt`, keduanya masuk `.gitignore`.
- Diverifikasi: hash NIK uji yang dihitung dari kunci di file sama dengan hasil `NikHasher::hash()` di server PRODULI.
- Dev SiLAKES (`dev.api.silakes`) tidak diisi: `.env`-nya dari Maret 2025 dan kodenya belum punya integrasi PRODULI.

## 8. Risiko

| Risiko | Mitigasi |
|---|---|
| "Setujui Semua" 100 usulan memicu 100 event | Job digabung per pasien dengan jeda 5 detik (unique job) |
| PRODULI mati saat event dikirim | Retry bertahap + sinkron harian tetap menangkap |
| Event berulang | `event_id` unik di `silakes_inbound_events` |
| Loop event | Tidak mungkin: arah event hanya SiLAKES ke PRODULI, refresh PRODULI tidak menulis ke SiLAKES |
| Pasien digabung di SiLAKES | Event `patient.merged` memindahkan riwayat dan klasifikasi |
| Kunci kepemilikan mengunci penetapan yang keliru | Override tetap tersedia, dan ada daftar pasien yang ditetapkan lewat kecamatan (§9 no. 2) |
| NIK asli tersimpan di PRODULI | Keputusan sadar pemilik sistem. Akses tetap per role dan per puskesmas |

Di luar cakupan, dicatat saja: SiLAKES mengubah hasil GDA di atas 200 menjadi "Gula Darah Puasa = 135" khusus untuk PRODULI (`IntegrationController::mapLabResultParameter`). Dengan prinsip baru di §2, ini sebaiknya dipindah ke aturan klasifikasi PRODULI. Bisa jadi pekerjaan terpisah.

## 9. Keputusan review (2026-11-16)

| No | Keputusan |
|---|---|
| 1 | Tarik semua pasien Prolanis (10.776), yang belum ada hasil lab 2026 ditandai "Belum ada hasil lab" |
| 2 | Semua pasien yang sudah punya puskesmas dikunci saat deploy, plus filter "Ditetapkan lewat kecamatan" |
| 3 | Poin: +10/level membaik, -10/level memburuk, +2 retensi, bonus kunjungan +5 |
| 4 | Snapshot harian disimpan selamanya |
| 5 | **Tidak**: hasil lab Prolanis tetap masuk lewat sinkron 02:00. Yang real-time HANYA usulan data pasien dari PRODULI yang sudah di-ACC di SiLAKES: data pasien di PRODULI langsung berubah dan pegawai pengusul diberi notifikasi. Event `lab_result.finalized` di §3a dicoret |
| 6 | Benar: tab Riwayat Sinkron + status usulan langsung berubah |
| 7 | `foto_ktp` milik SiLAKES TIDAK dikirim ke PRODULI. Arah sebaliknya boleh: foto hasil scan KTP di PRODULI disimpan di PRODULI dan ikut dikirim ke SiLAKES bersama usulan (§10) |

Prinsip pemilik sistem: sistem saling mempermudah tetapi tetap punya prosedur (perubahan data tetap lewat persetujuan staf Labkesda).

## 10. Perbaikan mendesak: form usulan lengkap + scan KTP (dikerjakan 2026-11-16)

Temuan: `/dashboard/pasien/14029` tidak punya isian NIK. NIK pasien itu di SiLAKES berisi 16 digit berawalan `0000` (NIK pengganti), dan aturan lama hanya membuka koreksi NIK untuk NIK kosong, "-", atau berisi no. reg.

Yang dibangun:

- NIK boleh diajukan untuk **semua** pasien (`NikKoreksi`), tetap dijaga format NIK asli (bukan `0000...`, bukan no. reg, tanggal di NIK masuk akal) dan tidak boleh sudah dipakai pasien lain.
- Form "Ajukan Update Data Pasien" dan form pasien baru di pengiriman sampel lengkap seperti registrasi SiLAKES: NIK, nama, tempat & tanggal lahir, jenis kelamin, alamat, RT/RW, kecamatan & desa (daftar kanonik), HP, email, pekerjaan, status perkawinan, agama, golongan darah, Prolanis, BPJS, FKTP (daftar sama dengan SiLAKES). Komponen bersama `PatientDataFields`.
- Verifikasi seperti SiLAKES: tanggal lahir & jenis kelamin terisi otomatis dari NIK; bila tidak cocok, ditandai dan butuh centang konfirmasi (dicek juga di backend). HP harus nomor Indonesia, BPJS 13 digit. Pasien baru dengan NIK yang sudah terdaftar ditolak.
- Form usulan hanya mengirim isian yang **berubah**.
- Scan KTP (kamera atau berkas): foto diperkecil di browser (batas upload PHP production 2 MB), disimpan di PRODULI (tabel `ktp_scans`, disk `produli.storage.ktp_photos_disk`), dibaca lewat endpoint baru SiLAKES `POST /integration/ktp-ocr` (mesin OCR yang sama dengan registrasi SiLAKES, token baca). Saat usulan dikirim, foto ikut ke SiLAKES (`foto_ktp_base64`) dan alamat dari KTP masuk ke alamat KTP pasien (sumber `produli_ktp`), bukan domisili.
- SiLAKES: usulan menerima `tempat_lahir`, `email`, `fktp`, `foto_ktp`; usulan pasien baru menyimpan identitas lengkap + foto; approve NIK yang sudah dipakai pasien lain ditolak 409 dengan pesan jelas (sebelumnya error 500); halaman staf menampilkan foto KTP.

Status: live di production 2026-11-17 (keempat repo).

## 12. FKTP pengirim, cari pasien sambil mengetik, pendaftaran pasien baru langsung (dikerjakan 2026-11-17)

Permintaan user: FKTP otomatis dari pengirim; di `/dashboard/pengiriman-sampel` cari pasien sambil mengetik (10 kartu), bila tidak ada otomatis menawarkan daftar pasien baru dengan mode yang bisa bolak-balik; pendaftaran pasien baru mengikuti alur perubahan data (dikonfirmasi staf Labkesda, atau langsung masuk bila auto approve menyala), no. reg dibuat di Labkesda, hasilnya langsung kembali ke PRODULI dan petugas pengisi dinotifikasi.

Ini sekaligus mengerjakan bagian Fase 3 (event SiLAKES ke PRODULI) yang sudah disetujui di §9 no. 5: keputusan usulan PRODULI didorong seketika. Hasil lab TIDAK ikut (tetap sinkron 02:00).

**FKTP = puskesmas pengirim**
- Pasien baru di pengiriman sampel: FKTP dikunci ke puskesmas pemilik batch (ditampilkan tetap, dipaksa juga di backend).
- Usulan perubahan data: bila puskesmas pasien dipetakan dari pengirim sampel (`pengirim_matched`), FKTP dikunci ke puskesmas itu dan ikut di setiap usulan. Pengirim tidak jelas (rujukan perorangan, dipetakan dari desa saja) tetap memilih manual. SiLAKES tidak membuat baris usulan bila FKTP sudah sama.

**Cari sambil mengetik** (`GET /pengiriman-sampel/patient-search?q=&batch_id=`)
- Nama (min. 2 huruf), atau NIK / no. BPJS / no. reg (min. 4 angka, awalan). Maks 10, Prolanis didahulukan, cakupan puskesmas user. Semua pasien di cakupan ikut, bukan hanya yang sudah Prolanis, supaya pasien terdaftar tidak didaftarkan ulang. Kartu yang sudah di antrian ditandai.
- Satu dialog, dua mode. Tidak ketemu: tombol "Daftarkan pasien baru", kata kunci dibawa ke form (16 digit = NIK, 13 digit = BPJS, teks = nama). "Kembali ke cari pasien" kapan saja. Escape hanya menutup di mode cari (isian panjang tidak hilang karena satu tombol).

**Pendaftaran langsung**
1. PRODULI menyimpan baris antrian (`registrasi_status = mengirim`, `diinput_oleh_user_id`), lalu `RegisterPasienBaruToSilakesJob` memanggil `POST /integration/patient-registrations` (token tulis yang sama dengan prolanis-deliveries).
2. SiLAKES: NIK sudah jadi pasien → balas pasien itu (`sudah_terdaftar`), PRODULI langsung menautkan baris. NIK sudah menunggu → balas usulan yang sama (aman diulang). Selain itu → usulan `patient_registration_proposals` sumber `produli_pendaftaran`.
3. Ditinjau di halaman Usulan Pembaruan Data SiLAKES (panel "Pendaftaran pasien baru dari PRODULI"), atau disetujui otomatis oleh switch yang sama (sesi, jeda ~3 detik, tepat sekali, log, penyapu tiap menit). Disetujui = pasien + no. reg dibuat.
4. SiLAKES mendorong `patient_registration.approved/rejected` ke `POST /api/v1/integration/silakes-events` PRODULI (HMAC sama, `event_id` unik, retry 8x). PRODULI: upsert pasien, tautkan baris (`terdaftar` + no. reg), notifikasi pengisi (bel, FCM), sinyal realtime ke layar antrian.
5. Cadangan: `produli:cek-pendaftaran-silakes` tiap 5 menit membaca `GET /integration/patient-registrations/{id}`; layar antrian juga mengecek tiap 30 detik selama ada pendaftaran berjalan.
6. Saat sampel tiba, baris yang sudah terdaftar dikirim sebagai pasien lama; yang masih menunggu membawa `registration_proposal_id` sehingga SiLAKES tidak membuat usulan kedua. Gagal kirim di awal tidak menghilangkan pasien: datanya ikut batch seperti alur lama.
7. Wilayah pasien baru tak terpetakan → puskesmas pengirim (`manual`), supaya pasien tidak hilang dari daftar puskesmas yang mendaftarkannya.

**Keputusan usulan perubahan data** juga didorong (`patient_field_update.approved/rejected`): data pasien di PRODULI langsung berubah, dan pengaju dinotifikasi SEKALI setelah semua field dari satu pengajuan diputuskan (`silakes_field_update_refs`).

**Konfigurasi production yang dibutuhkan:** `.env` SiLAKES `PRODULI_API_BASE_URL=https://api.produli.labkesdasumenep.id`. Tanpa ini tidak ada yang didorong; PRODULI tetap mendapat hasil lewat pengecekan berkala.

## 11. Pertanyaan review awal (arsip)

1. **Cakupan pasien:** tarik semua 10.776 pasien Prolanis (yang belum punya hasil lab 2026 ditandai "Belum ada hasil lab"), atau tetap 6.757 yang punya hasil lab sejak 2026-01-01? Usulan saya: tarik semua, supaya daftar Prolanis puskesmas lengkap.
2. **Kunci kepemilikan:** 2.302 pasien ditetapkan lewat kecamatan (bukan pengirim atau desa). Ikut dikunci semua saat deploy? Usulan saya: ya, ditambah filter "Ditetapkan lewat kecamatan" di `/dashboard/pasien` supaya puskesmas bisa memeriksa dan memindahkan.
3. **Angka poin:** +10 per level membaik, -10 per level memburuk, +2 retensi, bonus kunjungan +5. Setuju atau ada angka lain?
4. **Masa simpan snapshot:** semua hari disimpan selamanya, atau harian 90 hari lalu mingguan? Usulan saya: simpan semua, ukurannya masih kecil.
5. **Hasil lab real-time:** hasil lab Prolanis yang baru final juga dikirim real-time (`lab_result.finalized`), jadi klasifikasi risiko dan poin langsung bergerak tanpa menunggu 02:00?
6. **Arti "riwayat sinkron pasien ikut terupdate":** saya artikan sebagai tab Riwayat Sinkron per pasien (§5) plus status usulan yang langsung berubah. Benar?
7. **`foto_ktp`:** tetap tidak dikirim ke PRODULI?
