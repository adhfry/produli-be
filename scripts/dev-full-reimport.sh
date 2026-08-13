#!/usr/bin/env bash
#
# KHUSUS branch `dev`/lingkungan simulasi -- opsi "NUKLIR", JARANG dipakai. Menghapus
# TOTAL database dev dan menarik ulang dump produksi dari nol, lalu jalankan seed
# simulasi lagi. Untuk reset rutin sehari-hari/gladi bersih, pakai
# dev-reset-simulation.sh (jauh lebih cepat, tidak perlu dump lagi).
#
# Kapan skrip ini benar-benar dibutuhkan: data dev rusak/tidak konsisten, atau ingin
# menyegarkan data dump produksi dengan yang lebih baru sebelum presentasi.
#
# Pemakaian: scripts/dev-full-reimport.sh path/ke/dump-produksi-baru.sql

set -euo pipefail

cd "$(dirname "$0")/.."

DUMP_FILE="${1:-}"
if [ -z "$DUMP_FILE" ] || [ ! -f "$DUMP_FILE" ]; then
  echo "ERROR: berikan path file dump SQL yang valid sebagai argumen pertama." >&2
  echo "Pemakaian: scripts/dev-full-reimport.sh path/ke/dump-produksi-baru.sql" >&2
  exit 1
fi

APP_ENV_VALUE=$(grep -E '^APP_ENV=' .env | head -1 | cut -d '=' -f2- || true)
if [ "$APP_ENV_VALUE" = "production" ]; then
  echo "ERROR: APP_ENV=production di .env -- skrip ini HANYA untuk server dev/simulasi, dibatalkan." >&2
  exit 1
fi

DB_DATABASE_VALUE=$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d '=' -f2- || true)
if [ -z "$DB_DATABASE_VALUE" ]; then
  echo "ERROR: DB_DATABASE kosong di .env." >&2
  exit 1
fi

echo "PERINGATAN: ini akan MENGHAPUS TOTAL isi database '$DB_DATABASE_VALUE' lalu menarik ulang dari '$DUMP_FILE'."
read -r -p "Ketik nama database ('$DB_DATABASE_VALUE') persis untuk konfirmasi: " CONFIRM
if [ "$CONFIRM" != "$DB_DATABASE_VALUE" ]; then
  echo "Dibatalkan -- input tidak cocok."
  exit 1
fi

DB_HOST_VALUE=$(grep -E '^DB_HOST=' .env | head -1 | cut -d '=' -f2- || echo "127.0.0.1")
DB_USERNAME_VALUE=$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d '=' -f2- || echo "root")
DB_PASSWORD_VALUE=$(grep -E '^DB_PASSWORD=' .env | head -1 | cut -d '=' -f2- || echo "")

echo "== Drop & buat ulang database =="
mysql -h "$DB_HOST_VALUE" -u "$DB_USERNAME_VALUE" ${DB_PASSWORD_VALUE:+-p"$DB_PASSWORD_VALUE"} \
  -e "DROP DATABASE IF EXISTS \`$DB_DATABASE_VALUE\`; CREATE DATABASE \`$DB_DATABASE_VALUE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "== Restore dump baru =="
mysql -h "$DB_HOST_VALUE" -u "$DB_USERNAME_VALUE" ${DB_PASSWORD_VALUE:+-p"$DB_PASSWORD_VALUE"} \
  "$DB_DATABASE_VALUE" < "$DUMP_FILE"

echo "== Migrate + seed simulasi =="
php artisan migrate --force
php artisan produli:seed-simulation

echo "=== Selesai. Database dev sudah disegarkan total dari dump baru. ==="
