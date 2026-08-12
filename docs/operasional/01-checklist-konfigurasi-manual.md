# Checklist Konfigurasi Manual — PRODULI Backend

Dokumen ini daftar semua nilai `.env`/konfigurasi yang **sengaja tidak diisi oleh AI agent**
selama pengembangan — baik karena itu kredensial rahasia, baik karena butuh keputusan/akun di
luar repo ini (Google Cloud Console, provider S3, provider email, dst). Kode di backend sudah
lengkap dan siap pakai begitu nilai-nilai ini diisi; tidak ada perubahan kode yang dibutuhkan
untuk item apa pun di bawah, kecuali disebutkan eksplisit.

Urutan bebas, tapi disusun dari yang paling menghambat (tidak ada ini = fitur inti tidak
jalan) ke yang paling opsional.

---

## 1. Integrasi SiLAKES (docs/planning/01, 04)

Ini **koordinasi dengan tim/repo SiLAKES**, bukan sesuatu yang bisa PRODULI putuskan sendiri.

| Variabel                    | Dari mana                                                                                                     | Catatan                                                                                                                                                          |
| --------------------------- | ------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SILAKES_BASE_URL`          | URL server SiLAKES (dev/staging/prod beda)                                                                    | Sudah terisi untuk lokal (`http://localhost:8000`)                                                                                                               |
| `SILAKES_API_TOKEN`         | Sanctum ability token `integration:read-lab-results`, dibuat di sisi **SiLAKES** untuk service-account PRODULI | Read-only — patients, lab-results, master-wilayah                                                                                                                |
| `SILAKES_WRITE_API_TOKEN`   | Sanctum ability token `integration:write-field-data`, **terpisah** dari token baca di atas                    | **JANGAN** disamakan dengan `SILAKES_API_TOKEN` — dipakai HANYA untuk `POST .../pembaruan-lapangan` (docs/planning/01 §9)                                        |
| `PRODULI_INTEGRATION_SECRET` | **Harus SAMA PERSIS** dengan secret HMAC yang dipakai SiLAKES untuk verifikasi `X-Signature`                  | Ini bukan secret yang PRODULI generate sendiri secara sepihak — kedua sistem wajib pakai nilai yang sama, koordinasikan dengan siapa pun yang pegang repo SiLAKES |

**Kapan wajib diisi:** sebelum `produli:sync-silakes` dijalankan sungguhan (bukan test), dan
sebelum kader bisa submit laporan kunjungan (butuh `SILAKES_WRITE_API_TOKEN` untuk push balik
geo ke SiLAKES — lihat `SyncFieldUpdateToSilakesJob`).

---

## 2. Login Google (docs/planning/02 §6)

Langkah di **Google Cloud Console** (console.cloud.google.com):

1. Buat project baru (atau pakai yang sudah ada).
2. **APIs & Services → OAuth consent screen** — isi nama aplikasi, logo (opsional), email
   support. Kalau masih tahap testing, pilih "Testing" (bukan "In production") dan tambahkan
   email penguji ke daftar test users — lebih cepat daripada menunggu verifikasi Google.
3. **APIs & Services → Credentials → Create Credentials → OAuth client ID** — tipe
   "Web application".
4. **Authorized redirect URIs** — WAJIB tambahkan **DUA** URL (bukan cuma satu), karena PRODULI
   punya dua alur Google yang beda (login vs tautkan akun):
    - `{APP_URL}/auth/google/callback` — alur login (`GoogleAuthController::callback`)
    - `{APP_URL}/auth/google/link/callback` — alur tautkan akun dari user yang sudah login email/password (`GoogleAuthController::linkCallback`)

    Ganti `{APP_URL}` sesuai environment (contoh lokal: `http://localhost:8033`; produksi:
    `https://api.produli.labkesdasumenep.id`). **Kalau lupa menambahkan salah satu, Google
    akan menolak dengan error `redirect_uri_mismatch`** — bukan bug di kode, cek dulu daftar ini.

5. Setelah dibuat, Google kasih **Client ID** dan **Client Secret** — isi ke:

    | Variabel               | Isi dengan                                                             |
    | ---------------------- | ---------------------------------------------------------------------- |
    | `GOOGLE_CLIENT_ID`     | Client ID dari langkah 5                                               |
    | `GOOGLE_CLIENT_SECRET` | Client secret dari langkah 5                                           |
    | `GOOGLE_REDIRECT_URI`  | HARUS sama persis dengan salah satu URL di langkah 4 (yang alur login) |

**Catatan keamanan:** `GOOGLE_CLIENT_SECRET` setara password aplikasi — jangan pernah commit ke
git, jangan taruh di frontend Nuxt (backend yang pegang, sesuai docs/planning/02 §6).

**Kalau consent screen masih mode "Testing":** cuma email yang ada di daftar test users yang
bisa login — kalau staf/kader beneran mau pakai login Google, tambahkan email mereka ke daftar
itu, atau ajukan verifikasi Google App kalau sudah siap production sungguhan (proses ini bisa
makan waktu berhari-hari, ajukan lebih awal kalau memang mau dipakai luas).

---

## 3. Penyimpanan Foto Kunjungan — S3 atau MinIO (docs/planning/02 §5)

Laravel yang push ke storage (bukan Nuxt langsung) — kredensial di sini, bukan di frontend.

**Pilih salah satu:**

### Opsi A — AWS S3 asli

| Variabel                      | Isi dengan                                                                           |
| ----------------------------- | ------------------------------------------------------------------------------------ |
| `AWS_ACCESS_KEY_ID`           | Access key dari IAM user AWS (buat user KHUSUS untuk ini, jangan pakai root account) |
| `AWS_SECRET_ACCESS_KEY`       | Secret key pasangannya                                                               |
| `AWS_DEFAULT_REGION`          | Region bucket, mis. `ap-southeast-1` (Singapore, paling dekat ke Indonesia)          |
| `AWS_BUCKET`                  | Nama bucket (buat dulu di S3 console)                                                |
| `AWS_ENDPOINT`                | **KOSONGKAN** (biarkan default AWS)                                                  |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false`                                                                              |

IAM policy minimal untuk user ini: `s3:PutObject`, `s3:GetObject` di ARN bucket tsb saja —
jangan kasih akses penuh S3 kalau tidak perlu.

### Opsi B — MinIO self-hosted

| Variabel                      | Isi dengan                                                       |
| ----------------------------- | ---------------------------------------------------------------- |
| `AWS_ACCESS_KEY_ID`           | Access key MinIO (dibuat saat setup MinIO)                       |
| `AWS_SECRET_ACCESS_KEY`       | Secret key MinIO                                                 |
| `AWS_DEFAULT_REGION`          | Bebas, mis. `us-east-1` (MinIO tidak menegakkan region asli AWS) |
| `AWS_BUCKET`                  | Nama bucket yang dibuat di MinIO                                 |
| `AWS_ENDPOINT`                | URL server MinIO, mis. `https://minio.internal.example.com`      |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` — **WAJIB** untuk MinIO, beda dari AWS S3 asli            |

Bucket boleh **fully private** (tidak perlu public read) — PRODULI tidak pernah generate URL
publik langsung ke foto, semua akses lewat backend.

**Kapan wajib diisi:** sebelum endpoint `POST /api/v1/visit-reports` dipakai sungguhan (upload
foto kunjungan kader) — tanpa ini, `VisitReportService::submit()` akan gagal upload (sudah
ditangani sebagai error yang jelas, bukan silent failure — lihat catatan di
`VisitReportService::storePhoto()`).

---

## 4. Pengiriman Email

Saat ini `MAIL_MAILER=log` — email tidak benar-benar terkirim, cuma ditulis ke
`storage/logs/laravel.log`. Ini dipakai untuk:

- Email aktivasi akun staf/kader (`AccountActivationMail`)
- Email reset password (`QueuedResetPasswordNotification`)

**Pilih salah satu provider** (semua didukung Laravel out-of-the-box lewat driver `smtp`,
kecuali disebutkan pakai driver lain):

| Provider                       | Cocok untuk                                | Catatan                                                                                     |
| ------------------------------ | ------------------------------------------ | ------------------------------------------------------------------------------------------- |
| SMTP kantor/Dinkes (kalau ada) | Volume kecil, internal                     | Tanya admin IT setempat untuk host/port/kredensial                                          |
| Mailgun / Postmark / Resend    | Volume kecil-menengah, transactional email | Butuh domain terverifikasi (tambah DNS record) supaya tidak masuk spam                      |
| AWS SES                        | Kalau sudah pakai AWS untuk S3 juga        | Perlu keluar dari "sandbox mode" AWS SES dulu sebelum bisa kirim ke sembarang penerima      |
| Gmail SMTP                     | **Hindari untuk production**               | Ada limit ketat & gampang kena block Google kalau volume naik — OK untuk uji coba awal saja |

Isi di `.env` (contoh generik, sesuaikan field sesuai provider yang dipilih):

```
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@produli.labkesdasumenep.id
MAIL_FROM_NAME="PRODULI"
```

`MAIL_FROM_ADDRESS` idealnya pakai domain sendiri (`@produli.labkesdasumenep.id`) supaya
lolos SPF/DKIM — tanya provider domain/hosting cara setup DNS record-nya kalau belum ada.

**Kapan wajib diisi:** sebelum staf/kader pertama benar-benar didaftarkan lewat
`POST /api/v1/staff` atau `POST /api/v1/kader` — tanpa ini, mereka tidak akan pernah terima
email aktivasi (email cuma nyangkut di log server, tidak ada yang baca).

---

## 5. Redis (Cache & Queue)

Sudah dimigrasikan ke Predis (client PHP murni, portable Windows↔Linux). Untuk **production**:

- **Jangan** pakai Redis tanpa password di server yang bisa diakses dari luar — set
  `REDIS_PASSWORD` ke nilai acak yang kuat, dan pastikan port Redis (`6379`) **tidak terbuka ke
  internet** (firewall/security group cuma izinkan akses dari server aplikasi sendiri).
- Kalau pakai VPS Linux, install `redis-server` via package manager (`apt install
redis-server` di Ubuntu/Debian), lalu edit `/etc/redis/redis.conf`: set `requirepass
<password-kuat>`, dan pastikan `bind 127.0.0.1` (jangan biarkan default yang bisa listen ke
  semua interface) kalau Redis & aplikasi di server yang sama.
- Kalau pakai Redis managed service (mis. dari provider cloud), tinggal isi
  `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD` sesuai kredensial yang diberikan provider —
  `REDIS_CLIENT=predis` tetap dipakai, tidak perlu ganti.

| Variabel         | Production                                                               |
| ---------------- | ------------------------------------------------------------------------ |
| `REDIS_HOST`     | Host Redis production (bukan `127.0.0.1` kalau Redis di server terpisah) |
| `REDIS_PORT`     | Biasanya tetap `6379` kecuali dikustomisasi                              |
| `REDIS_PASSWORD` | **WAJIB diisi** (jangan `null` seperti di lokal)                         |

---

## 6. Horizon (Dashboard Monitoring Queue)

**Belum terinstall** — `laravel/horizon` butuh ekstensi `ext-pcntl`/`ext-posix` yang tidak
tersedia di PHP Windows sama sekali (bukan soal enable di `php.ini`, memang tidak bisa
dikompilasi untuk Windows). Kalau deploy ke VPS Linux nanti:

```bash
composer require laravel/horizon
php artisan horizon:install
```

Lalu setup **Supervisor** (bukan cuma jalankan `php artisan horizon` manual di terminal —
proses itu akan mati kalau SSH terputus) supaya Horizon jalan terus sebagai background service
dan otomatis restart kalau crash:

```ini
[program:produli-horizon]
process_name=%(program_name)s
command=php /path/ke/project/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/ke/project/storage/logs/horizon.log
stopwaitsecs=3600
```

Tanpa Horizon, queue tetap jalan lewat `php artisan queue:work` biasa (juga butuh Supervisor
config serupa) — cuma tidak ada dashboard `/horizon` untuk monitoring visual.

---

## 7. Scheduler (Cron)

`produli:sync-silakes` (harian, throttle 48 jam) dan `produli:send-visit-reminders` (twice
daily) TIDAK akan pernah jalan sendiri di production kecuali cron job berikut didaftarkan di
server (`crontab -e`):

```
* * * * * cd /path/ke/project && php artisan schedule:run >> /dev/null 2>&1
```

Baris ini jalan **tiap menit**, tapi Laravel sendiri yang menentukan kapan command di
`routes/console.php` benar-benar dieksekusi (`dailyAt('02:00')`, `twiceDaily(6, 16)`, dst) —
bukan berarti sync jalan tiap menit.

---

## 8. Database Production

- Buat **user MySQL khusus untuk aplikasi** (bukan `root`), dengan privilege terbatas ke
  database `produli_db` saja.
- `DB_PASSWORD` **wajib diisi** (kosong seperti di lokal tidak boleh dipakai di production).

---

## 9. Dasar Keamanan Aplikasi

| Variabel    | Production                                                                                           | Kenapa                                                                                                                               |
| ----------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `APP_ENV`   | `production`                                                                                         | Bukan `local` — mengubah perilaku error handling, cache default, dll                                                                 |
| `APP_DEBUG` | `false`                                                                                              | **KRITIS** — kalau `true` di production, stack trace lengkap (termasuk isi `.env`!) bisa bocor ke response error yang dilihat publik |
| `APP_KEY`   | Generate BARU khusus production (`php artisan key:generate`), **jangan** reuse key dari `.env` lokal | Key ini dipakai enkripsi cookie/session — kalau sama dengan lokal, data terenkripsi bisa "bocor" lintas environment                  |

---

## 10. Ringkasan Checklist

- [ ] `SILAKES_API_TOKEN` + `SILAKES_WRITE_API_TOKEN` + `PRODULI_INTEGRATION_SECRET` dikoordinasikan dengan tim SiLAKES
- [ ] Google Cloud Console: OAuth client dibuat, **dua** redirect URI terdaftar (login + link), consent screen dikonfigurasi
- [ ] `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` terisi
- [ ] S3 atau MinIO dipilih, bucket dibuat, kredensial terisi (`AWS_*`)
- [ ] Provider email dipilih, domain terverifikasi (SPF/DKIM), kredensial `MAIL_*` terisi
- [ ] Redis production: password terisi, port tidak terbuka ke internet
- [ ] (Opsional, VPS Linux) Horizon terinstall + Supervisor config
- [ ] Cron `schedule:run` terdaftar di server
- [ ] User MySQL khusus aplikasi (bukan root) + `DB_PASSWORD` terisi
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` baru khusus production

Lihat `docs/operasional/02-rekomendasi-env-production.md` untuk contoh `.env` lengkap siap-pakai
untuk domain production yang sudah ditentukan.
