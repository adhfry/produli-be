# Rekomendasi `.env` Production — KOPIPU Smart Backend

Domain yang jadi acuan dokumen ini:

- **Backend (Laravel, repo ini):** `api.kopipu-smart.labkesdasumenep.id`
- **Frontend (Nuxt):** `kopipu-smart.labkesdasumenep.id`

Keduanya berbagi **registrable domain/eTLD+1 yang sama** (`labkesdasumenep.id`) — secara teknis
ini *same-site* (bukan cross-site), jadi `SameSite=Lax` untuk cookie refresh token sudah benar
dan cukup (keputusan final, docs/planning/02 §6) — **tidak perlu** `SameSite=None`.

Placeholder `__ISI_MANUAL__` di bawah **wajib** diganti sebelum deploy — jangan biarkan
tertinggal, aplikasi akan gagal start atau fitur terkait tidak berfungsi. Untuk penjelasan
DARI MANA masing-masing nilai didapat, lihat
`docs/operasional/01-checklist-konfigurasi-manual.md`.

---

```env
APP_NAME="KOPIPU Smart"
APP_ENV=production
# Generate BARU khusus production (php artisan key:generate --show), JANGAN reuse key dari .env lokal.
APP_KEY=__ISI_MANUAL__
# WAJIB false -- true di production membocorkan stack trace + isi config ke response error publik.
APP_DEBUG=false
APP_TIMEZONE=Asia/Jakarta
APP_URL=https://api.kopipu-smart.labkesdasumenep.id

APP_LOCALE=id
APP_FALLBACK_LOCALE=id
APP_FAKER_LOCALE=id_ID

APP_MAINTENANCE_DRIVER=file
APP_MAINTENANCE_STORE=database

# --- Integrasi SiLAKES (docs/planning/01, 04) -- koordinasikan dengan tim/repo SiLAKES,
# nilai produksi BEDA dari yang dipakai dev/staging.
KOPIPU_INTEGRATION_SECRET=__ISI_MANUAL__
KOPIPU_NIK_HASH_SECRET=__ISI_MANUAL__
SILAKES_BASE_URL=__ISI_MANUAL__
SILAKES_API_TOKEN=__ISI_MANUAL__
# Ability token TERPISAH untuk endpoint tulis pembaruan-lapangan (docs/planning/01 §9) --
# JANGAN samakan dengan SILAKES_API_TOKEN di atas.
SILAKES_WRITE_API_TOKEN=__ISI_MANUAL__

# --- Login Google (docs/planning/02 §6) -- lihat checklist §2 untuk cara dapatkan nilai ini.
# REDIRECT_URI ini untuk alur LOGIN (GoogleAuthController::callback) -- alur TAUTKAN AKUN
# (linkCallback) pakai path /auth/google/link/callback, ikut APP_URL yang sama, tidak perlu
# env terpisah, TAPI kedua path ini harus SAMA-SAMA terdaftar di Google Cloud Console.
GOOGLE_CLIENT_ID=__ISI_MANUAL__
GOOGLE_CLIENT_SECRET=__ISI_MANUAL__
GOOGLE_REDIRECT_URI=https://api.kopipu-smart.labkesdasumenep.id/auth/google/callback

SANCTUM_TOKEN_EXPIRATION=30
# 'lax' SUDAH BENAR untuk domain ini (same-site, lihat catatan di atas) -- JANGAN ganti ke
# 'none' kecuali domain frontend/backend benar-benar pindah ke registrable domain berbeda.
REFRESH_COOKIE_SAMESITE=lax
FRONTEND_URL=https://kopipu-smart.labkesdasumenep.id

# --- 7-layer visit validation (docs/planning/02 §3/§10) -- nilai default sudah dipakai sejak
# dev, tinjau ulang setelah beberapa minggu pemakaian lapangan (terlalu ketat/longgar?).
KOPIPU_FACE_DETECTION_ENABLED=false
KOPIPU_GPS_MAX_ACCURACY_METERS=100
KOPIPU_GPS_MAX_AGE_SECONDS=300
KOPIPU_GEOFENCE_RADIUS_VERIFIED=150
KOPIPU_GEOFENCE_RADIUS_APPROXIMATE=3000
KOPIPU_EXIF_MAX_AGE_SECONDS=300
KOPIPU_EXIF_GPS_TOLERANCE_METERS=200

# --- Reminder kader H-1/hari-H (docs/planning/02 §8)
KOPIPU_REMINDER_H1_TIME=16:00
KOPIPU_REMINDER_SAME_DAY_TIME=06:00

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
# 'debug' terlalu berisik untuk production -- naikkan ke 'info' atau 'warning'.
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=__ISI_MANUAL__
DB_PORT=3306
DB_DATABASE=kopipu_db
# User MySQL KHUSUS aplikasi (bukan root), privilege dibatasi ke database ini saja.
DB_USERNAME=__ISI_MANUAL__
DB_PASSWORD=__ISI_MANUAL__

SESSION_DRIVER=database
SESSION_LIFETIME=120
# true -- production selalu HTTPS, cookie session wajib Secure.
SESSION_ENCRYPT=false
SESSION_PATH=/
# null (host-only) -- session HANYA dipakai alur login Google (butuh CSRF state OAuth),
# dan itu seluruhnya terjadi di domain api.* sendiri, tidak perlu dibagi ke subdomain lain.
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

CACHE_STORE=redis
CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

# Predis (pure PHP) -- portable, tidak perlu compile ulang ekstensi kalau pindah server.
REDIS_CLIENT=predis
REDIS_HOST=__ISI_MANUAL__
# WAJIB diisi di production (beda dari lokal yang null) -- lihat checklist §5.
REDIS_PASSWORD=__ISI_MANUAL__
REDIS_PORT=6379

# --- Email (lihat checklist §4 untuk pilihan provider) -- contoh generik SMTP, sesuaikan
# field dengan provider yang benar-benar dipilih.
MAIL_MAILER=smtp
MAIL_HOST=__ISI_MANUAL__
MAIL_PORT=587
MAIL_USERNAME=__ISI_MANUAL__
MAIL_PASSWORD=__ISI_MANUAL__
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@kopipu-smart.labkesdasumenep.id
MAIL_FROM_NAME="KOPIPU Smart"

# --- S3/MinIO untuk foto kunjungan kader (docs/planning/02 §5) -- lihat checklist §3 untuk
# pilih AWS S3 asli vs MinIO self-hosted, dua opsi punya kombinasi ENDPOINT/PATH_STYLE beda.
AWS_ACCESS_KEY_ID=__ISI_MANUAL__
AWS_SECRET_ACCESS_KEY=__ISI_MANUAL__
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=__ISI_MANUAL__
# Kosongkan kalau AWS S3 asli; isi URL server MinIO kalau pakai MinIO.
AWS_ENDPOINT=__ISI_MANUAL_ATAU_KOSONGKAN__
# false untuk AWS S3 asli, true untuk MinIO.
AWS_USE_PATH_STYLE_ENDPOINT=__ISI_MANUAL__
KOPIPU_VISIT_PHOTOS_DISK=s3

VITE_APP_NAME="${APP_NAME}"
```

---

## Perbedaan kunci dari `.env` lokal (rekap)

| Variabel | Lokal | Production | Alasan |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | Mengubah default error handling/cache Laravel |
| `APP_DEBUG` | `true` | `false` | Cegah kebocoran stack trace/config ke publik |
| `APP_KEY` | (punya lokal) | Generate baru | Jangan reuse antar environment |
| `APP_URL` | `http://localhost:8033` | `https://api.kopipu-smart.labkesdasumenep.id` | Domain asli |
| `FRONTEND_URL` | `http://localhost:3033` | `https://kopipu-smart.labkesdasumenep.id` | Domain asli, dipakai juga untuk `allowed_origins` di `config/cors.php` |
| `GOOGLE_REDIRECT_URI` | `http://localhost:8033/auth/google/callback` | `https://api.kopipu-smart.labkesdasumenep.id/auth/google/callback` | Harus terdaftar juga di Google Cloud Console |
| `SESSION_ENCRYPT` | `false` | `true` | Production selalu HTTPS |
| `LOG_LEVEL` | `debug` | `info` | Kurangi noise log production |
| `REDIS_PASSWORD` | `null` | wajib diisi | Keamanan — lihat checklist §5 |
| `DB_USERNAME`/`DB_PASSWORD` | `root`/kosong | user khusus + password | Jangan pakai root di production |
| `MAIL_MAILER` | `log` | provider asli (`smtp`/dst) | Supaya email benar-benar terkirim |

## Yang TIDAK berubah (dan kenapa)

- `REFRESH_COOKIE_SAMESITE=lax` — sudah benar untuk domain ini (same-site), jangan diganti tanpa
  alasan kuat (lihat catatan di atas dokumen ini).
- `SESSION_DOMAIN=null` — session cuma dipakai alur login Google, host-only sudah cukup.
- `KOPIPU_FACE_DETECTION_ENABLED=false` — keputusan arsitektur v1 (docs/planning/02 §10), bukan
  soal environment.
- Threshold 7-layer validation (GPS/geofence/EXIF) — nilai default sejak dev, tinjau ulang
  berdasar data lapangan sungguhan, bukan berdasar environment.
