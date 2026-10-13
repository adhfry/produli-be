#!/usr/bin/env bash
# Deploy PRODULI production -- dipanggil GitHub Actions lewat SSH forced command:
#   authorized_keys root: command="/usr/local/sbin/produli-deploy",no-pty,... ssh-ed25519 ...
# Kunci CI HANYA bisa menjalankan skrip ini (tidak bisa membuka shell). Argumen dibaca dari
# SSH_ORIGINAL_COMMAND: "backend" atau "frontend". Selalu men-deploy origin/main.
#
# Salinan terpasang: /usr/local/sbin/produli-deploy (root:root 0755). Ubah DI REPO lalu pasang
# ulang manual -- skrip ini sengaja tidak memperbarui dirinya sendiri dari git.
set -euo pipefail

TARGET="${SSH_ORIGINAL_COMMAND:-${1:-}}"
LOG=/var/log/produli-deploy.log
BE=/var/www/api.produli.labkesdasumenep.id
FE=/var/www/produli.labkesdasumenep.id
NODE_BIN=/root/.nvm/versions/node/v24.13.1/bin
PM2_APP=produli

exec > >(tee -a "$LOG") 2>&1
echo "=== $(date -u '+%F %T UTC') deploy '${TARGET}' ==="

# Satu deploy pada satu waktu (backend & frontend berbagi kunci, server yang sama).
exec 9>/var/lock/produli-deploy.lock
flock -w 900 9 || { echo "ABORT: deploy lain masih berjalan"; exit 1; }

git_as_www() { sudo -u www-data git -C "$1" "${@:2}"; }

update_repo() { # $1 = direktori; mencetak commit lama
  local dir=$1
  # Repo milik www-data; operasi git sebagai root di masa lalu meninggalkan objek milik root
  # yang membuat fetch gagal ("insufficient permission ... .git/objects") -- rapikan dulu.
  chown -R www-data:www-data "$dir/.git"
  git_as_www "$dir" fetch --quiet origin main
  if [ -n "$(git_as_www "$dir" status --porcelain --untracked-files=no)" ]; then
    echo "ABORT: ada perubahan lokal terlacak di $dir -- selesaikan manual dulu:"
    git_as_www "$dir" status --short --untracked-files=no
    exit 1
  fi
  OLD=$(git_as_www "$dir" rev-parse HEAD)
  git_as_www "$dir" merge --ff-only --quiet origin/main
  NEW=$(git_as_www "$dir" rev-parse HEAD)
  echo "$dir: ${OLD:0:7} -> ${NEW:0:7} $(git_as_www "$dir" log -1 --format=%s)"
}

changed() { # $1 = direktori, sisanya = path yang dicek
  local dir=$1; shift
  [ "$OLD" != "$NEW" ] && [ -n "$(git_as_www "$dir" diff --name-only "$OLD" "$NEW" -- "$@")" ]
}

deploy_backend() {
  update_repo "$BE"
  cd "$BE"
  if changed "$BE" composer.json composer.lock; then
    echo "composer.json/lock berubah -> composer install"
    sudo -u www-data env COMPOSER_HOME=/tmp/composer-www-data composer install --no-dev --optimize-autoloader --no-interaction
  fi
  sudo -u www-data php artisan migrate --force
  sudo -u www-data php artisan config:cache
  sudo -u www-data php artisan route:cache
  # OPcache: reload (tanpa downtime) supaya bytecode lama tidak terus dipakai. Pool dipakai
  # bersama SiLAKES -- reload, bukan restart.
  systemctl reload php8.2-fpm
  # Worker queue memuat kode lama di memori; queue:restart = keluar setelah job berjalan selesai,
  # systemd (Restart=always) menyalakan ulang dengan kode baru.
  sudo -u www-data php artisan queue:restart
  code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://api.produli.labkesdasumenep.id/api/v1/patients/1)
  [ "$code" = 401 ] || { echo "HEALTHCHECK GAGAL: api.produli /patients/1 -> $code (harap 401)"; exit 1; }
  echo "backend OK (healthcheck 401 tanpa login)"
}

deploy_frontend() {
  update_repo "$FE"
  cd "$FE"
  export PATH="$NODE_BIN:$PATH"
  if changed "$FE" package.json package-lock.json; then
    echo "package.json/lock berubah -> npm ci"
    npm ci --no-audit --no-fund
    chown -R www-data:www-data node_modules # dipasang root (node via nvm root), pemilik tetap www-data
  fi
  local ts backup
  ts=$(date +%Y%m%d%H%M%S)
  backup=/root/produli-output-backup-$ts
  cp -a .output "$backup"
  ls -dt /root/produli-output-backup-* 2>/dev/null | tail -n +4 | xargs -r rm -rf # simpan 3 terakhir

  rollback() {
    echo "ROLLBACK: memulihkan .output dari $backup"
    rm -rf .output && cp -a "$backup" .output
    pm2 restart "$PM2_APP" >/dev/null
  }

  if ! npm run build; then
    echo "BUILD GAGAL"; rollback; exit 1
  fi
  pm2 restart "$PM2_APP" >/dev/null
  for i in $(seq 1 20); do
    code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3033/ || true)
    [ "$code" = 200 ] && break
    sleep 2
  done
  if [ "$code" != 200 ]; then
    echo "HEALTHCHECK GAGAL: 127.0.0.1:3033 -> $code"; rollback; exit 1
  fi
  echo "frontend OK (healthcheck 200)"
}

case "$TARGET" in
  backend) deploy_backend ;;
  frontend) deploy_frontend ;;
  *) echo "Argumen tidak dikenal: '$TARGET' (pakai: backend | frontend)"; exit 2 ;;
esac
echo "=== selesai $(date -u '+%F %T UTC') ==="
