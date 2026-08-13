# Setup Lingkungan Dev/Simulasi VPS — `dev.produli.labkesdasumenep.id`

> Runbook manual — **dijalankan oleh user sendiri di VPS** (atau dipandu step-by-step
> lewat output yang di-paste balik ke chat Claude Code), BUKAN dieksekusi otomatis oleh
> agent lewat SSH. Ini SEPARATE TOTAL dari lingkungan produksi
> (`produli.labkesdasumenep.id` / `api.produli.labkesdasumenep.id`) — jangan pernah
> pakai nama database, service, atau path yang sama dengan produksi.

## Kenapa lingkungan ini ada

Presentasi end-to-end ke Kepala Dinas Kesehatan P2KB Kabupaten Sumenep butuh
lingkungan yang bisa dicoba nyata (login berbagai peran, kunjungan lapangan dengan
validasi GPS asli) tanpa risiko mengubah data produksi. Database dev di-restore dari
**mysqldump data produksi asli** (keputusan sadar — data pasien asli ikut masuk ke
server ini, jadi akses server ini harus tetap dijaga selayaknya server produksi),
ditambah 86 akun demo (password sama, `12345678`, dikenal publik — JANGAN pernah pakai
pola ini di produksi) dan 1 pasien sintetis khusus untuk uji GPS supaya rumah pasien
asli tidak pernah dipakai sebagai titik uji coba publik.

## Prasyarat (sekali saja)

1. **DNS**: tambahkan A record `dev.produli.labkesdasumenep.id` dan
   `dev.api.produli.labkesdasumenep.id` menunjuk ke IP VPS yang sama dengan produksi
   (lewat panel DNS provider — di luar jangkauan skrip apa pun).
2. **Clone branch `dev`** (bukan `main`!) di kedua repo, sibling folder dari clone
   produksi yang sudah ada:
   ```bash
   git clone -b dev https://github.com/adhfry/kopipu-smart-be.git produli-backend-dev
   git clone -b dev https://github.com/adhfry/kopipu-smart-fe.git produli-frontend-dev
   ```
   (Nama repo GitHub tetap `kopipu-smart-*` — sengaja tidak di-rename, lihat doc 13.)
3. **Nginx** — 2 server block BARU, path/port terpisah total dari produksi:
   - `dev.api.produli.labkesdasumenep.id` → proxy ke PHP-FPM di
     `produli-backend-dev/public` (mirror pola block produksi di doc 13, ganti
     `root`/`server_name` saja).
   - `dev.produli.labkesdasumenep.id` → `proxy_pass http://127.0.0.1:3001;` (port
     BEDA dari produksi, lihat `scripts/dev-deploy.sh` di repo frontend).
   - SSL: `sudo certbot --nginx -d dev.produli.labkesdasumenep.id -d
     dev.api.produli.labkesdasumenep.id` (setelah DNS resolve).
4. **Google OAuth (opsional)** — kalau mau login Google berfungsi di dev, tambahkan
   redirect URI baru `https://dev.api.produli.labkesdasumenep.id/auth/google/callback`
   di Google Cloud Console. TIDAK wajib untuk demo — semua 86 akun demo bisa login
   email+password langsung.

## Setup database dev (sekali di awal, atau tiap kali mau segarkan data)

1. Ambil dump database produksi (dijalankan di server produksi atau lewat akses yang
   sudah ada):
   ```bash
   mysqldump -u <user> -p <nama_db_produksi> > /tmp/produli_prod_dump.sql
   ```
2. Salin file dump itu ke lokasi yang bisa diakses `produli-backend-dev` (mis. `scp`
   antar server, atau langsung di server yang sama kalau dev & produksi satu VPS).

## Backend — `.env` (`produli-backend-dev/.env`)

Salin dari `.env.example`, isi field yang **BEDA dari produksi**:
```
APP_ENV=local
APP_URL=https://dev.api.produli.labkesdasumenep.id
FRONTEND_URL=https://dev.produli.labkesdasumenep.id
DB_CONNECTION=mysql
DB_DATABASE=produli_db_dev        # JANGAN sama dengan nama database produksi
SANCTUM_STATEFUL_DOMAINS=dev.produli.labkesdasumenep.id
GOOGLE_REDIRECT_URI=https://dev.api.produli.labkesdasumenep.id/auth/google/callback
```
Field lain (MAIL_*, S3/MinIO, SILAKES_*, WA_API_*, Firebase) boleh reuse nilai
produksi kalau memang mau notifikasi email/WA sungguhan ikut teruji saat demo — atau
kosongkan/arahkan ke log driver kalau tidak mau kirim beneran selama gladi bersih.

## Backend — setup pertama kali

```bash
cd produli-backend-dev
chmod +x scripts/*.sh   # kalau bit executable hilang setelah clone
scripts/dev-setup.sh /tmp/produli_prod_dump.sql
```
Skrip ini: `git pull` → `composer install` → restore dump (dengan konfirmasi manual,
tidak akan jalan tanpa mengetik "ya") → `migrate --force` → cache config/route →
`php artisan produli:seed-simulation` (86 akun + pasien uji GPS) → benerin permission
→ restart `produli-queue-dev.service` (kalau sudah terpasang, lihat template systemd
di bawah).

**Queue worker dev** (systemd unit, `/etc/systemd/system/produli-queue-dev.service`):
```ini
[Unit]
Description=PRODULI Dev Queue Worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/dev.produli.labkesdasumenep.id/produli-backend-dev
ExecStart=/usr/bin/php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now produli-queue-dev.service
```

**Scheduler dev** (opsional untuk demo — cron `www-data`, HANYA kalau mau
`produli:sync-silakes`/reminder/care-visit generation ikut jalan otomatis di dev; kalau
tidak, lewati saja supaya tidak ada proses tak terduga selama presentasi):
```
* * * * * cd /var/www/dev.produli.labkesdasumenep.id/produli-backend-dev && php artisan schedule:run >> /dev/null 2>&1
```

## Frontend — `.env` (`produli-frontend-dev/.env`)

```
NUXT_PUBLIC_API_BASE=https://dev.api.produli.labkesdasumenep.id/api/v1
NUXT_PUBLIC_SITE_URL=https://dev.produli.labkesdasumenep.id
NUXT_PUBLIC_APP_ENV_LABEL=SIMULASI
```
(Firebase/tile server boleh sama dengan produksi — independen dari domain, lihat doc
13.) `NUXT_PUBLIC_APP_ENV_LABEL` menampilkan ribbon kuning "MODE SIMULASI" di semua
halaman supaya tidak ada yang salah kira ini data produksi live.

## Frontend — deploy

```bash
cd produli-frontend-dev
chmod +x scripts/*.sh
scripts/dev-deploy.sh
```
Default port `3001`, proses manager PM2 — kalau VPS produksi pakai systemd (bukan
PM2), jalankan `PROCESS_MANAGER=systemd scripts/dev-deploy.sh` sebagai gantinya
(siapkan unit `produli-frontend-dev.service` dulu, mirror template `produli-
frontend.service` di doc 13, ganti `PORT=3001`).

## Reset simulasi (dipakai berkali-kali saat gladi bersih)

```bash
cd produli-backend-dev
scripts/dev-reset-simulation.sh
```
Selesai dalam hitungan detik, TIDAK menyentuh data dump/86 akun — cuma mengembalikan
pasien uji GPS ke state awal (`geo_status='approximate'`, lokasi 1). Jalankan ini
di antara setiap kali gladi bersih skenario GPS di bawah.

Kalau butuh reset TOTAL (jarang, mis. data korup atau mau tarik dump baru):
```bash
scripts/dev-full-reimport.sh /tmp/produli_prod_dump_baru.sql
```

## Checklist Uji Coba GPS Geofence (jalankan fisik sebelum hari-H)

Login sebagai `pkm.pandian.tenagakesehatan1@gmail.com` (password `12345678`) di HP,
buka daftar tugas — pasien "**[SIMULASI] Pasien Uji GPS Kota Sumenep**" harus muncul.

1. **Berdiri di mana pun sekitar Kota Sumenep** (radius s.d. 3000m dari Lokasi 1:
   `-7.012297, 113.857923`) → submit laporan kunjungan, centang "konfirmasi lokasi
   pasien" → **HARUS berhasil**. Pasien otomatis jadi `geo_status='verified'`, radius
   mengetat ke 150m.
2. **Jalan ke Lokasi 2** (`-7.014266, 113.858532`, ~229m dari Lokasi 1) atau **Lokasi
   3** (`-7.014096, 113.863611`, ~660m), coba submit laporan lagi → **HARUS ditolak**
   ("Lokasi kader ...m dari titik pasien (batas 150m)") — membuktikan pengetatan radius
   bekerja nyata dengan GPS asli.
3. **Jalan ke Kecamatan Manding** (~beberapa km dari Kota Sumenep), coba submit →
   **HARUS ditolak lebih jelas lagi** (jarak jauh lebih besar dari radius mana pun).
4. Sebelum ulangi dari Langkah 1 untuk gladi berikutnya:
   `scripts/dev-reset-simulation.sh` di server.

## Yang TIDAK boleh terjadi

- Skrip apa pun di sini menyentuh `.env`/database/service **produksi**.
- `produli:seed-simulation` (atau seeder di baliknya) berhasil jalan kalau
  `APP_ENV=production` — semua sudah di-guard eksplisit di kode.
- Password `12345678` dipakai untuk akun mana pun di luar 86 akun demo ini.
