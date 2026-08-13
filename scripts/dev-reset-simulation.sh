#!/usr/bin/env bash
#
# KHUSUS branch `dev`/lingkungan simulasi -- reset CEPAT sebelum gladi bersih/demo
# ulang. TIDAK menyentuh data dump produksi maupun 86 akun demo -- cuma mengembalikan
# pasien+assignment uji GPS ke state awal (geo_status='approximate', lokasi 1,
# assignment 'pending', laporan kunjungan lama dihapus). Aman dipanggil berkali-kali,
# selesai dalam hitungan detik.
#
# Pemakaian: scripts/dev-reset-simulation.sh

set -euo pipefail

cd "$(dirname "$0")/.."

APP_ENV_VALUE=$(grep -E '^APP_ENV=' .env | head -1 | cut -d '=' -f2- || true)
if [ "$APP_ENV_VALUE" = "production" ]; then
  echo "ERROR: APP_ENV=production di .env -- skrip ini HANYA untuk server dev/simulasi, dibatalkan." >&2
  exit 1
fi

echo "== Reset simulasi (pasien uji GPS -> state awal) =="
php artisan produli:seed-simulation --reset-demo

echo "=== Selesai. Simulasi siap dijalankan ulang dari Langkah 1 (lihat checklist di docs/planning/14-setup-dev-simulasi-vps.md). ==="
