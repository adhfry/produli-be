# 13 — Panduan Rename & Deploy VPS: kopipu-smart → produli

> **Untuk siapa dokumen ini**: dipaste ke agent Claude yang berjalan LANGSUNG di VPS
> (bukan di komputer lokal). Agent VPS itu punya akses shell ke server produksi yang
> SUDAH menjalankan kopipu-smart — dokumen ini memandunya rename total ke produli
> DAN redeploy kode terbaru, dari nol sampai selesai.
>
> **Sebelum kirim dokumen ini ke agent VPS**: pastikan seluruh pekerjaan revisi (Bu
> Kadis) sudah di-commit dan di-push ke GitHub dari sisi lokal (`produli-frontend` dan
> `produli-backend`, repo `adhfry/kopipu-smart-fe` dan `adhfry/kopipu-smart-be` —
> nama repo GitHub-nya SENGAJA belum di-rename, cukup isinya, supaya remote URL yang
> sudah dikonfigurasi di VPS tidak ikut berubah). Kalau ada juga perubahan di
> `api-administrasi-labkesda` (SiLAKES) yang belum ter-push, push itu juga dulu —
> tapi JANGAN redeploy SiLAKES lewat panduan ini, itu sistem produksi terpisah yang
> sudah live dan tidak masuk cakupan rename/redeploy ini.

## 0. Prinsip kerja WAJIB — discovery dulu, jangan menebak

Server produksi ini **belum pernah diinspeksi oleh AI agent manapun** sebelum
sekarang. Jangan asumsikan struktur nginx/systemd/PM2 di bawah ini persis sama
dengan kenyataan di server — semua PATTERN di bawah adalah *template paling umum*
untuk stack Nuxt 4 (Node SSR) + Laravel 11 (PHP-FPM) + MySQL + Redis, BUKAN hasil
pembacaan config asli.

**Urutan wajib**: (1) Discovery — baca apa yang benar-benar ada, (2) Backup, (3)
Rename mengikuti apa yang ditemukan di langkah 1, (4) Deploy kode baru, (5) Verifikasi.

Jangan hapus/timpa apa pun sebelum backup-nya ada. Jangan restart Nginx dengan config
belum divalidasi (`nginx -t`). Kalau di tengah jalan ada yang tidak sesuai dugaan
dokumen ini (mis. struktur folder beda, path service beda), **berhenti dan laporkan
ke user** — jangan improvisasi mengarang path yang tidak ada.

---

## 1. Discovery — kumpulkan fakta sebelum menyentuh apa pun

Jalankan semua ini dan simpan hasilnya (jangan langsung lanjut sebelum tahu isinya):

```bash
# Cari semua referensi "kopipu" di server (folder, config, service, cron)
sudo find /var/www -maxdepth 2 -iname "*kopipu*"
sudo find /etc/nginx -iname "*kopipu*"
sudo systemctl list-units --all | grep -i kopipu
pm2 list 2>/dev/null | grep -i kopipu
sudo crontab -l 2>/dev/null | grep -i kopipu
crontab -l 2>/dev/null | grep -i kopipu   # cron milik user non-root yang menjalankan app
sudo find /etc/letsencrypt/live -maxdepth 1 -iname "*kopipu*"

# Baca isi nginx site yang ditemukan (JANGAN diedit dulu, cuma dibaca)
sudo cat /etc/nginx/sites-available/kopipu-smart* 2>/dev/null
ls -la /etc/nginx/sites-enabled/ | grep -i kopipu

# Struktur folder project yang ditemukan
sudo ls -la /var/www/kopipu-smart.labkesdasumenep.id/ 2>/dev/null
sudo find /var/www/kopipu-smart.labkesdasumenep.id -maxdepth 2 -type d 2>/dev/null

# Apakah backend & frontend satu folder atau dua folder terpisah (mis. /backend, /frontend
# di dalam folder domain, atau dua domain/subdomain berbeda)?
sudo find /var/www/kopipu-smart.labkesdasumenep.id -maxdepth 1 -iname "*.env" 2>/dev/null
sudo find /var/www/kopipu-smart.labkesdasumenep.id -iname "artisan" -maxdepth 3 2>/dev/null
sudo find /var/www/kopipu-smart.labkesdasumenep.id -iname "nuxt.config.ts" -maxdepth 3 2>/dev/null

# Versi runtime yang tersedia
php -v; node -v; npm -v; composer --version
mysql --version 2>/dev/null; redis-cli ping 2>/dev/null
```

Tentukan dari hasil di atas:
- **Path project pasti**: kemungkinan `/var/www/kopipu-smart.labkesdasumenep.id/backend`
  + `/var/www/kopipu-smart.labkesdasumenep.id/frontend` (satu domain, dua sub-folder),
  ATAU dua folder domain terpisah kalau API-nya di subdomain (mis.
  `api.kopipu-smart.labkesdasumenep.id`). **Ikuti yang DITEMUKAN, bukan asumsi ini.**
- **Nama service systemd/PM2 yang benar-benar ada** untuk: queue worker Laravel, Nuxt
  Node server, PHP-FPM pool (kalau pool khusus per-site, bukan pool default `www`).
- **Apakah backend dan frontend satu domain (path-based, mis. `/api/*` diproxy ke
  Laravel) atau dua domain/subdomain berbeda.**
- **User Linux yang menjalankan proses** (jangan asumsikan root — cek `ps aux | grep -E 'php-fpm|node'`).

Simpan ringkasan discovery ini di percakapan sebelum lanjut ke langkah 2.

---

## 2. Backup — WAJIB sebelum rename apa pun

```bash
sudo mkdir -p /root/backup-kopipu-rename-$(date +%Y%m%d)
cd /root/backup-kopipu-rename-$(date +%Y%m%d)

# Backup database (sesuaikan nama DB dari .env project lama, biasanya "kopipu_db" atau serupa)
sudo mysqldump -u root -p --databases <nama_db_lama> > db-backup.sql

# Backup .env (JANGAN pernah commit file ini ke git)
sudo find /var/www/kopipu-smart.labkesdasumenep.id -name ".env" -exec cp {} .env-backup-{} \; 2>/dev/null

# Backup nginx config
sudo cp -r /etc/nginx/sites-available/*kopipu* .

# Backup storage/uploads Laravel KALAU disk lokal dipakai (bukan cuma S3/MinIO) — cek
# PRODULI_VISIT_PHOTOS_DISK di .env lama, kalau "s3" berarti file foto sudah di
# MinIO eksternal, TIDAK perlu backup folder storage/app lokal untuk itu. Tetap backup
# storage/logs kalau ingin arsip.
sudo tar -czf storage-backup.tar.gz /var/www/kopipu-smart.labkesdasumenep.id/backend/storage 2>/dev/null || true
```

Konfirmasi ukuran `db-backup.sql` masuk akal (bukan 0 byte) sebelum lanjut.

---

## 3. Rename folder project

Sesuaikan path persis dengan hasil discovery langkah 1. Contoh (satu domain, dua sub-folder):

```bash
sudo systemctl stop <nama-service-queue-worker> <nama-service-nuxt-node> 2>/dev/null
pm2 stop <nama-proses-pm2> 2>/dev/null

sudo mv /var/www/kopipu-smart.labkesdasumenep.id /var/www/produli.labkesdasumenep.id
```

Kalau ternyata folder domain-nya BUKAN nama domain persis (mis. cuma `/var/www/kopipu-smart`
tanpa suffix domain), rename ke `/var/www/produli` — ikuti pola penamaan yang sudah ada,
jangan paksa menambah suffix domain kalau originalnya tidak begitu.

---

## 4. Rename domain — DNS dulu, baru SSL

**Ini bagian yang PALING SERING butuh user turun tangan** — DNS A/AAAA record untuk
`produli.labkesdasumenep.id` harus sudah mengarah ke IP VPS ini SEBELUM certbot bisa
menerbitkan sertifikat. Kalau `dig produli.labkesdasumenep.id` atau
`nslookup produli.labkesdasumenep.id` belum resolve ke IP VPS, **STOP di sini dan
laporkan ke user untuk menambahkan DNS record dulu** — jangan lanjut ke certbot,
akan gagal terus dan bisa kena rate-limit Let's Encrypt kalau dipaksa coba berkali-kali.

```bash
dig +short produli.labkesdasumenep.id
# Bandingkan dengan IP VPS ini:
curl -s ifconfig.me
```

Kalau sudah cocok:

```bash
sudo certbot --nginx -d produli.labkesdasumenep.id
# (tambahkan -d www.produli.labkesdasumenep.id kalau domain lama juga punya www variant —
# cek dulu apakah sertifikat lama punya SAN untuk www, lihat:
# sudo certbot certificates | grep -A5 kopipu-smart)
```

Sertifikat domain LAMA (`kopipu-smart.labkesdasumenep.id`) boleh dibiarkan apa adanya
sampai masa berlakunya habis (tidak perlu dihapus paksa) — kecuali user secara
eksplisit minta domain lama benar-benar dinonaktifkan/di-redirect.

---

## 5. Rename Nginx config

```bash
cd /etc/nginx/sites-available
sudo cp kopipu-smart.labkesdasumenep.id.conf produli.labkesdasumenep.id.conf
```

Edit `produli.labkesdasumenep.id.conf`, ganti SEMUA kemunculan:
- `kopipu-smart.labkesdasumenep.id` → `produli.labkesdasumenep.id`
- `/var/www/kopipu-smart.labkesdasumenep.id` → `/var/www/produli.labkesdasumenep.id`
- Path sertifikat SSL (`ssl_certificate`/`ssl_certificate_key`) — certbot langkah 4
  biasanya SUDAH menulis ulang path ini otomatis kalau dijalankan dengan `--nginx`
  langsung di file baru; kalau certbot dijalankan SEBELUM file di-rename, jalankan
  ulang `sudo certbot --nginx -d produli.labkesdasumenep.id` supaya certbot
  menyuntikkan block SSL yang benar ke file yang sudah di-rename ini.

Kalau ada `upstream` block (mis. proxy ke port Node/PHP-FPM socket) — nama upstream
BOLEH tetap seadanya (nama internal, tidak ada dampak user-facing), tapi rename juga
kalau ingin konsisten.

```bash
sudo rm /etc/nginx/sites-available/kopipu-smart.labkesdasumenep.id.conf
sudo rm -f /etc/nginx/sites-enabled/kopipu-smart.labkesdasumenep.id.conf
sudo ln -s /etc/nginx/sites-available/produli.labkesdasumenep.id.conf /etc/nginx/sites-enabled/

sudo nginx -t   # WAJIB sebelum reload — jangan reload kalau ada error
sudo systemctl reload nginx
```

### Template referensi (KALAU discovery langkah 1 tidak menemukan config existing
sama sekali — pakai ini sebagai titik awal, BUKAN pengganti config yang sudah ada)

```nginx
# Frontend (Nuxt SSR Node server di-reverse-proxy)
server {
    listen 80;
    server_name produli.labkesdasumenep.id;

    location / {
        proxy_pass http://127.0.0.1:3000;   # sesuaikan port aktual Nuxt Node server
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }

    # Backend API — HANYA kalau backend memang di-proxy lewat path /api pada domain
    # yang SAMA (bukan subdomain terpisah). Sesuaikan dengan temuan discovery.
    location /api {
        alias /var/www/produli.labkesdasumenep.id/backend/public;
        try_files $uri $uri/ /index.php?$query_string;

        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.2-fpm.sock;  # sesuaikan versi PHP-FPM
            fastcgi_index index.php;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $request_filename;
        }
    }
}
```

Certbot (langkah 4) akan menambahkan block `listen 443 ssl` + redirect HTTP→HTTPS
secara otomatis di atas template ini.

---

## 6. Deploy kode backend (Laravel)

```bash
cd /var/www/produli.labkesdasumenep.id/backend   # sesuaikan path hasil discovery

# Kalau remote git BELUM diarahkan ke repo produli (nama repo GitHub TETAP
# kopipu-smart-be per catatan di kepala dokumen ini) -- pastikan remote sudah benar:
git remote -v
git fetch origin
git checkout main
git pull origin main

composer install --no-dev --optimize-autoloader

# .env: PALING PENTING -- salin dari backup langkah 2, JANGAN generate baru dari
# .env.example (akan kehilangan semua kredensial produksi: SILAKES_*, GOOGLE_*,
# AWS_*, WA_API_*, FIREBASE_CREDENTIALS, dll -- lihat daftar lengkap di
# produli-backend/.env.example). Kalau file .env lama tidak ketemu di backup, godok
# manual pakai .env.example sebagai kerangka + minta user isi kredensial satu per satu,
# JANGAN pernah menebak/mengarang nilai kredensial.
cp /root/backup-kopipu-rename-*/env-backup-* .env

# WAJIB update field-field yang MEMANG harus berubah karena rename (yang lain JANGAN disentuh):
#   APP_NAME=PRODULI
#   APP_URL=https://produli.labkesdasumenep.id   (BUKAN /api -- root domain backend API)
#   FRONTEND_URL=https://produli.labkesdasumenep.id
#   GOOGLE_REDIRECT_URI=https://produli.labkesdasumenep.id/auth/google/callback
#     (WAJIB juga didaftarkan ulang di Google Cloud Console -> OAuth client -> Authorized
#     redirect URIs, kalau tidak login Google akan gagal redirect_uri_mismatch)
#   FIREBASE_CREDENTIALS=<path absolut baru kalau folder file service account ikut pindah>
php artisan key:generate --show   # HANYA kalau APP_KEY di .env lama kosong -- kalau
                                    # sudah ada APP_KEY lama, JANGAN generate ulang,
                                    # itu akan membuat semua data terenkripsi lama
                                    # (kalau ada) tidak bisa didekripsi lagi.

php artisan config:clear
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Queue worker & scheduler (systemd)

```bash
sudo cp /etc/systemd/system/kopipu-smart-queue.service /etc/systemd/system/produli-queue.service 2>/dev/null
```

Kalau service lama tidak ditemukan lewat nama itu, buat baru:

```ini
# /etc/systemd/system/produli-queue.service
[Unit]
Description=PRODULI Laravel Queue Worker
After=network.target redis.service mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/produli.labkesdasumenep.id/backend
ExecStart=/usr/bin/php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl disable --now kopipu-smart-queue.service 2>/dev/null
sudo rm -f /etc/systemd/system/kopipu-smart-queue.service
sudo systemctl daemon-reload
sudo systemctl enable --now produli-queue.service
sudo systemctl status produli-queue.service --no-pager
```

Scheduler (`Schedule::command()` di `routes/console.php` — sync SiLAKES harian
02:00, reminder kunjungan 2x sehari, generate care-visits 03:00 harian) jalan lewat
cron, BUKAN service terpisah:

```bash
sudo crontab -u www-data -e
# Pastikan baris ini ADA dan path-nya sudah diarahkan ke folder baru:
# * * * * * cd /var/www/produli.labkesdasumenep.id/backend && php artisan schedule:run >> /dev/null 2>&1
```

Kalau crontab lama masih mereferensikan path `kopipu-smart.labkesdasumenep.id`,
edit baris itu (jangan tambah baris duplikat baru).

---

## 7. Deploy kode frontend (Nuxt)

```bash
cd /var/www/produli.labkesdasumenep.id/frontend   # sesuaikan path hasil discovery

git remote -v
git fetch origin
git checkout main
git pull origin main

npm ci
```

`.env` frontend (dibaca Nuxt lewat `runtimeConfig`, lihat `NUXT_PUBLIC_*` di
`nuxt.config.ts`) — salin dari backup, lalu update yang WAJIB berubah karena rename:

```bash
cp /root/backup-kopipu-rename-*/env-backup-* .env
```

```
NUXT_PUBLIC_API_BASE=https://produli.labkesdasumenep.id/api/v1
NUXT_PUBLIC_SITE_URL=https://produli.labkesdasumenep.id
# NUXT_PUBLIC_FIREBASE_* -- TIDAK perlu berubah (proyek Firebase produli-abd5b sudah
# pakai nama produli sejak awal, independen dari domain hosting)
```

```bash
npm run build
```

### Proses Node server (systemd atau PM2 — ikuti yang SUDAH dipakai, jangan campur dua-duanya)

**Kalau PM2**:
```bash
pm2 stop kopipu-smart-frontend 2>/dev/null
pm2 delete kopipu-smart-frontend 2>/dev/null
cd /var/www/produli.labkesdasumenep.id/frontend
PORT=3000 pm2 start .output/server/index.mjs --name produli-frontend
pm2 save
```

**Kalau systemd**, pola sama seperti queue worker di langkah 6:

```ini
# /etc/systemd/system/produli-frontend.service
[Unit]
Description=PRODULI Nuxt Frontend
After=network.target

[Service]
Environment=PORT=3000
Environment=NODE_ENV=production
WorkingDirectory=/var/www/produli.labkesdasumenep.id/frontend
ExecStart=/usr/bin/node .output/server/index.mjs
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl disable --now kopipu-smart-frontend.service 2>/dev/null
sudo rm -f /etc/systemd/system/kopipu-smart-frontend.service
sudo systemctl daemon-reload
sudo systemctl enable --now produli-frontend.service
```

---

## 8. Rename database (opsional tapi disarankan untuk konsistensi)

Kalau nama database MySQL lama masih `kopipu_db` (lihat lokal: dev sudah direname ke
`produli_db`, produksi biasanya menyusul):

```bash
mysql -u root -p -e "CREATE DATABASE produli_db;"
mysqldump -u root -p kopipu_db | mysql -u root -p produli_db
mysql -u root -p -e "DROP DATABASE kopipu_db;"   # HANYA setelah verifikasi produli_db lengkap
```

Update `DB_DATABASE=produli_db` di `.env` backend, lalu `php artisan config:cache` ulang.

**Ini opsional** — kalau user tidak secara eksplisit minta rename database (beda dari
rename folder/domain/nginx yang eksplisit diminta), boleh dilewati dulu dan
dilaporkan sebagai catatan terbuka.

---

## 9. Verifikasi akhir

```bash
sudo systemctl status nginx produli-queue produli-frontend --no-pager
sudo systemctl status php8.2-fpm --no-pager   # sesuaikan versi

curl -I https://produli.labkesdasumenep.id
curl -sI https://produli.labkesdasumenep.id/api/v1/patients   # boleh 401 (butuh auth), yang penting BUKAN 502/connection refused

php artisan tinker --execute="echo App\Models\User::count();"   # dari folder backend, pastikan konek DB & data ada
```

Buka `https://produli.labkesdasumenep.id` di browser, pastikan:
- Halaman landing tampil (logo, branding PRODULI — bukan lagi kopipu-smart).
- Login berhasil (kredensial akun yang sudah ada di DB produksi lama tetap jalan,
  TIDAK di-reset oleh proses ini).
- Login via Google berhasil (kalau `GOOGLE_REDIRECT_URI` di langkah 6 sudah
  didaftarkan ulang di Google Cloud Console).
- Sinkronisasi SiLAKES dari dashboard berhasil (uji koneksi ke `SILAKES_BASE_URL`
  produksi, BUKAN localhost).

---

## 10. Yang TIDAK termasuk cakupan panduan ini

- **Rename nama repo GitHub** (`kopipu-smart-fe`/`kopipu-smart-be`) — sengaja
  dibiarkan, cuma ISI kode-nya yang sudah pakai branding PRODULI. Rename nama repo
  GitHub itu sendiri butuh update remote URL di server (`git remote set-url`) kalau
  suatu saat memang mau dilakukan — bukan bagian dari task hosting ini.
- **api-administrasi-labkesda (SiLAKES)** — sistem produksi terpisah yang sudah
  live, TIDAK di-redeploy lewat panduan ini sama sekali.
- **Migrasi bucket MinIO/S3** dari domain lama (`storage.kopipu-smart.labkesdasumenep.cloud`)
  ke domain baru — kalau user ingin ini juga di-rename, itu long-lived production
  data (foto kunjungan kader), butuh keputusan terpisah dan proses migrasi
  tersendiri (bukan sekadar copy config), catat sebagai item terbuka.

## 11. Kalau ada langkah yang gagal / butuh keputusan manual

Laporkan ke user persis di titik mana macet, output error aslinya, dan JANGAN
mencoba workaround destruktif (force push, `rm -rf` tanpa backup, restart service
berkali-kali) untuk "memaksakan lolos" — user yang minta dikabari kalau ada yang
perlu dikerjakan manual olehnya, itu jalan keluar yang benar untuk kebuntuan di
tengah proses ini.
