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
# New log files keep group www-data (Reverb runs as www-data).
chmod g+s "${SHARED_DIR}/storage/logs" 2>/dev/null || true

echo "==> Switching symlink to new release..."
ln -nfs "${RELEASE_DIR}" "${APP_DIR}/current"

echo "==> Restarting services..."
# Reload whichever PHP-FPM version is running, so the server can move to a
# new PHP version without this script changing in step.
for fpm in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' | awk '{print $1}'); do
    sudo systemctl reload "${fpm}"
done
sudo supervisorctl restart etigo-worker:*
# Reverb and the telemetry gateway each hold a port. Stop the old process
# fully before starting the new one: `reverb:restart` only asks the server
# to stop, and supervisor started the new one while the old still held 8080
# (EADDRINUSE, 21 Oct 2026).
sudo supervisorctl restart etigo-reverb 2>/dev/null || true

# The telemetry gateway keeps its old code until restarted. A gateway started
# by hand in daemon mode (-d) sits outside supervisor, keeps the port and
# makes every supervisor start exit as "already running" (staging ran 20 Oct
# code until 24 Oct), so stop it through its pid file too and wait for the
# port to free up before starting.
TELEMETRY_PORT="$(php artisan tinker --execute='echo config("telemetry.port", 8081);' 2>/dev/null | tail -1)"
TELEMETRY_PORT="${TELEMETRY_PORT:-8081}"
telemetry_port_held() {
    ss -ltnH "sport = :${TELEMETRY_PORT}" | grep -q .
}

# Stop the telemetry gateway processes holding the port: each worker's
# Workerman master first (it restarts workers that exit), then the worker.
# Only our own processes titled EtigoTelemetryGateway, never another listener.
stop_leftover_telemetry() {
    local signal="$1" pid ppid masters="" workers=""
    for pid in $(ss -ltnpH "sport = :${TELEMETRY_PORT}" | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u); do
        ps -o cmd= -p "${pid}" | grep -q "EtigoTelemetryGateway" || continue
        workers="${workers} ${pid}"
        ppid="$(ps -o ppid= -p "${pid}" | tr -d ' ')"
        if [ -n "${ppid}" ] && ps -o cmd= -p "${ppid}" | grep -q "WorkerMan: master process"; then
            masters="${masters} ${ppid}"
        fi
    done
    # Each process once, masters first.
    for pid in $(echo "${masters}" | tr ' ' '\n' | sort -u) $(echo "${workers}" | tr ' ' '\n' | sort -u); do
        kill "-${signal}" "${pid}" 2>/dev/null || true
    done
}

wait_for_telemetry_port() {
    for _ in $(seq 1 "$1"); do
        telemetry_port_held || return 0
        sleep 1
    done
    ! telemetry_port_held
}

sudo supervisorctl stop etigo-telemetry 2>/dev/null || true
php artisan telemetry:serve stop 2>/dev/null || true
if ! wait_for_telemetry_port 15; then
    echo "    port ${TELEMETRY_PORT} still held; stopping leftover telemetry processes"
    stop_leftover_telemetry INT
    wait_for_telemetry_port 10 || { stop_leftover_telemetry KILL; wait_for_telemetry_port 5 || true; }
fi
if telemetry_port_held; then
    echo "ERROR: port ${TELEMETRY_PORT} is held by a process that is not the telemetry gateway; telemetry not started." >&2
    ss -ltnp "sport = :${TELEMETRY_PORT}" >&2 || true
    exit 1
fi
sudo supervisorctl start etigo-telemetry 2>/dev/null || true

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
