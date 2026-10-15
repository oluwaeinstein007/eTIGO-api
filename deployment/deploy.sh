#!/usr/bin/env bash
set -euo pipefail

# eTIGO API — Zero-downtime deployment script
# Runs on the server as deploy user

APP_DIR="/var/www/etigo-api"
RELEASES_DIR="${APP_DIR}/releases"
SHARED_DIR="${APP_DIR}/shared"
REPO="git@github.com:oluwaeinstein007/eTIGO-api.git"
BRANCH="${1:-staging}"
RELEASE=$(date +%Y%m%d%H%M%S)
RELEASE_DIR="${RELEASES_DIR}/${RELEASE}"
KEEP_RELEASES=5

echo "==> Deploying branch '${BRANCH}' as release ${RELEASE}..."

echo "==> Cloning repository..."
git clone --depth 1 --branch "${BRANCH}" "${REPO}" "${RELEASE_DIR}"
rm -rf "${RELEASE_DIR}/.git"

echo "==> Linking shared .env..."
ln -nfs "${SHARED_DIR}/.env" "${RELEASE_DIR}/.env"

echo "==> Linking shared storage..."
rm -rf "${RELEASE_DIR}/storage"
ln -nfs "${SHARED_DIR}/storage" "${RELEASE_DIR}/storage"

echo "==> Installing Composer dependencies..."
cd "${RELEASE_DIR}"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Ensuring reference data (system accounts, commission, tiers, multipliers)..."
php artisan db:seed --class=ReferenceDataSeeder --force

echo "==> Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Linking storage to public..."
php artisan storage:link

echo "==> Ensuring storage permissions..."
chmod -R 775 "${SHARED_DIR}/storage" 2>/dev/null || true

echo "==> Switching symlink to new release..."
ln -nfs "${RELEASE_DIR}" "${APP_DIR}/current"

echo "==> Restarting services..."
sudo systemctl reload php8.4-fpm
sudo supervisorctl restart etigo-worker:*
php artisan reverb:restart 2>/dev/null || sudo supervisorctl restart etigo-reverb 2>/dev/null || true
# The telemetry gateway is long-running and keeps the old code until restarted.
sudo supervisorctl restart etigo-telemetry 2>/dev/null || true

echo "==> Ensuring the scheduler cron entry..."
# Every scheduled job (earnings settlement, hold expiry, reconciliation,
# payouts, EV expiry, ...) depends on this entry.
if [ ! -f /etc/cron.d/etigo-scheduler ]; then
    echo "* * * * * deploy cd ${APP_DIR}/current && php artisan schedule:run >> /dev/null 2>&1" | sudo tee /etc/cron.d/etigo-scheduler > /dev/null
    sudo chmod 644 /etc/cron.d/etigo-scheduler
    echo "    installed /etc/cron.d/etigo-scheduler"
fi

echo "==> Cleaning old releases (keeping ${KEEP_RELEASES})..."
cd "${RELEASES_DIR}"
ls -1dt */ 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)) | xargs -r sudo rm -rf

echo "==> Deploy complete! Release: ${RELEASE}"
