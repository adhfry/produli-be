#!/usr/bin/env bash
#
# KHUSUS branch `dev`/lingkungan simulasi -- setup PERTAMA KALI (atau setelah
# git pull ada perubahan) untuk dev.api.produli.labkesdasumenep.id. Bukan untuk
# reset simulasi rutin (pakai dev-reset-simulation.sh untuk itu -- jauh lebih
# cepat, tidak import ulang dump).
#
# WAJIB dijalankan dari root repo produli-backend-dev (branch dev), dan HANYA di
# server dev -- skrip ini menolak jalan kalau APP_ENV di .env bukan "local" atau
# "dev" (defense in depth, sama seperti guard di seeder-nya sendiri).
#
# Pemakaian:
#   scripts/dev-setup.sh [path/ke/dump-produksi.sql]
#
# Kalau argumen dump diberikan DAN database dev (DB_DATABASE di .env) masih kosong,
# dump di-restore dulu sebelum migrate. Kalau tidak diberikan, skrip asumsi database
# sudah pernah di-restore sebelumnya (mis. dev-setup.sh dipanggil ulang setelah
# `git pull`) -- cuma jalankan migrate+seed seperti biasa.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  echo "ERROR: .env belum ada -- salin dari .env.example lalu isi APP_URL/DB_DATABASE/dst untuk dev sebelum menjalankan skrip ini." >&2
  exit 1
fi

APP_ENV_VALUE=$(grep -E '^APP_ENV=' .env | head -1 | cut -d '=' -f2- || true)
if [ "$APP_ENV_VALUE" = "production" ]; then
  echo "ERROR: APP_ENV=production di .env -- skrip ini HANYA untuk server dev/simulasi, dibatalkan." >&2
  exit 1
fi

DB_DATABASE_VALUE=$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d '=' -f2- || true)
if [ -z "$DB_DATABASE_VALUE" ]; then
  echo "ERROR: DB_DATABASE kosong di .env -- isi dulu (mis. produli_db_dev, JANGAN sama dengan nama database produksi)." >&2
  exit 1
fi

echo "== 1/8: git pull =="
git fetch origin
git checkout dev
git pull origin dev

echo "== 2/8: composer install =="
composer install --no-dev --optimize-autoloader

DUMP_FILE="${1:-}"
if [ -n "$DUMP_FILE" ]; then
  if [ ! -f "$DUMP_FILE" ]; then
    echo "ERROR: file dump '$DUMP_FILE' tidak ditemukan." >&2
    exit 1
  fi

  echo "== 3/8: restore dump produksi ke database '$DB_DATABASE_VALUE' =="
  echo "   (pastikan '$DB_DATABASE_VALUE' BUKAN nama database produksi asli!)"
  read -r -p "   Lanjutkan restore ke '$DB_DATABASE_VALUE'? (ketik 'ya' untuk lanjut) " CONFIRM
  if [ "$CONFIRM" != "ya" ]; then
    echo "Dibatalkan oleh user."
    exit 1
  fi

  DB_HOST_VALUE=$(grep -E '^DB_HOST=' .env | head -1 | cut -d '=' -f2- || echo "127.0.0.1")
  DB_USERNAME_VALUE=$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d '=' -f2- || echo "root")
  DB_PASSWORD_VALUE=$(grep -E '^DB_PASSWORD=' .env | head -1 | cut -d '=' -f2- || echo "")

  mysql -h "$DB_HOST_VALUE" -u "$DB_USERNAME_VALUE" ${DB_PASSWORD_VALUE:+-p"$DB_PASSWORD_VALUE"} \
    -e "CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE_VALUE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -h "$DB_HOST_VALUE" -u "$DB_USERNAME_VALUE" ${DB_PASSWORD_VALUE:+-p"$DB_PASSWORD_VALUE"} \
    "$DB_DATABASE_VALUE" < "$DUMP_FILE"
else
  echo "== 3/8: (dilewati -- tidak ada argumen dump, asumsi database sudah pernah di-restore) =="
fi

echo "== 4/8: migrate =="
php artisan migrate --force

echo "== 5/8: storage:link + cache config/route =="
php artisan storage:link || true
php artisan config:clear
php artisan config:cache
php artisan route:cache

echo "== 6/8: seed simulasi (86 akun demo + pasien uji GPS) =="
php artisan produli:seed-simulation

echo "== 7/8: permission storage/bootstrap/cache =="
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || echo "   (dilewati -- bukan root/tidak ada www-data, jalankan manual kalau perlu)"
chmod -R 775 storage bootstrap/cache

echo "== 8/8: restart queue worker dev =="
if systemctl list-unit-files | grep -q produli-queue-dev.service; then
  sudo systemctl restart produli-queue-dev.service
  echo "   produli-queue-dev.service direstart."
else
  echo "   Service produli-queue-dev.service belum terpasang -- lihat docs/planning/14-setup-dev-simulasi-vps.md untuk template systemd unit."
fi

echo ""
echo "=== Selesai. Login salah satu dari 86 akun demo (lihat SimulationUsersSeeder.php) untuk verifikasi. ==="
