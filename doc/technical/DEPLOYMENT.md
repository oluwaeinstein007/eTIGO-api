# eTIGO API — Deployment Guide

## Infrastructure Overview

| Component   | Detail                          |
|-------------|---------------------------------|
| Provider    | DigitalOcean Droplet             |
| OS          | Ubuntu 24.04 LTS                 |
| PHP         | 8.4 (FPM)                       |
| Web Server  | Nginx                           |
| Database    | PostgreSQL 16                    |
| Cache/Queue | Redis 7                         |
| Process Mgr | Supervisor (queue workers, Reverb) |
| Scheduler   | cron → `php artisan schedule:run` every minute |
| WebSockets  | Laravel Reverb (`BROADCAST_CONNECTION=reverb`) |
| CI/CD       | GitHub Actions                   |
| Deploy User | `deploy` (SSH key auth)          |
| App Path    | `/var/www/etigo-api/current`     |

## Server Layout

```
/var/www/etigo-api/
├── current -> releases/20261001233820   # symlink to active release
├── releases/
│   └── 20261001233820/                  # timestamped release directories
├── shared/
│   ├── .env                             # shared environment config
│   └── storage/                         # shared Laravel storage
│       ├── app/public/
│       ├── framework/{cache,sessions,views}/
│       └── logs/
```

## CI/CD Pipeline

The pipeline is defined in `.github/workflows/staging.yml` and triggers on:

- **Push to `staging`** — runs tests, then deploys to the staging server
- **Pull request to `staging`** — runs tests only (no deploy)

### Pipeline Steps

1. **Test Job** (matrix: PHP 8.4 — the server version — and 8.5 — local development): spins up PostgreSQL 16 and Redis 7 service containers, installs PHP with extensions, runs `composer install`, executes migrations and runs the full test suite. Both must pass before deploying. `composer.json` pins `config.platform.php` to the server's 8.4.26, so `composer update` on a newer local PHP cannot lock packages the server cannot run.
2. **Deploy Job** (push only): SSHs into the server and runs the deploy script, which clones the latest code, installs dependencies, runs migrations, caches config/routes/views, and swaps the symlink for zero-downtime.

### GitHub Secrets Required

| Secret             | Description                                      |
|--------------------|--------------------------------------------------|
| `SSH_PRIVATE_KEY`  | Private SSH key for the `deploy` user on the server |
| `SERVER_IP`        | Staging server IP address                        |

## Deployment Flow

### Automatic (CI/CD)

1. Create a feature branch and develop your changes
2. Open a PR targeting `staging`
3. CI runs tests automatically
4. Merge the PR into `staging`
5. CI deploys to the staging server automatically

### Manual Deployment

SSH into the server and run the deploy script:

```bash
ssh deploy@<SERVER_IP>
bash -c '
APP_DIR=/var/www/etigo-api
RELEASE=$(date +%Y%m%d%H%M%S)
RELEASE_DIR=${APP_DIR}/releases/${RELEASE}

git clone --depth 1 --branch staging git@github.com:oluwaeinstein007/eTIGO-api.git ${RELEASE_DIR}
rm -rf ${RELEASE_DIR}/.git
ln -nfs ${APP_DIR}/shared/.env ${RELEASE_DIR}/.env
rm -rf ${RELEASE_DIR}/storage
ln -nfs ${APP_DIR}/shared/storage ${RELEASE_DIR}/storage

cd ${RELEASE_DIR}
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=ReferenceDataSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link
ln -nfs ${RELEASE_DIR} ${APP_DIR}/current
'

sudo systemctl restart php8.4-fpm
sudo supervisorctl restart etigo-worker:*
php artisan reverb:restart
```

`ReferenceDataSeeder` is idempotent: it creates the ledger system accounts, the default commission rate, loyalty tiers and point multipliers only when they are missing, and never overwrites values an admin has changed. Payments, settlements and refunds fail without the system accounts, so it runs on every deploy.

## Background Processes

Three long-running pieces must be up for the platform to work, besides Nginx and PHP-FPM:

| Process | How it runs | What breaks without it |
|---------|-------------|------------------------|
| Queue workers | Supervisor `etigo-worker` (2 processes, `queue:work database`) | Driver matching and offer timeouts, final fare, push/in-app notifications, payout transfers, webhook processing, SOS check-ins and alerts |
| Scheduler | cron: `* * * * * deploy cd /var/www/etigo-api/current && php artisan schedule:run` (`/etc/cron.d/etigo-scheduler`) | Driver earnings settlement, wallet hold and top-up expiry, stuck top-up recovery, daily reconciliation and ledger check, weekly auto-payouts, fleet remittance settlement, EV reservation expiry, lifting 48-hour compliance suspensions, notification clean-up |
| Reverb | Supervisor `etigo-reverb` (`php artisan reverb:start`), proxied by Nginx | Live ride updates, driver offers over WebSocket and the admin SOS console (push notifications and polling endpoints still work) |
| Telemetry gateway (optional) | `php artisan telemetry:serve start` under Supervisor, port `TELEMETRY_SERVER_PORT` (8081) behind Nginx | High-frequency driver GPS over WebSocket. Without it drivers report location through `POST /driver/location`. `deploy.sh` restarts it (Supervisor `etigo-telemetry`) — it is long-running and would otherwise keep old code |

`provision.sh` sets up the queue workers and the scheduler, and every deploy (`deploy.sh`) installs the scheduler entry if it is missing and restarts the telemetry gateway. Check an existing server with `cat /etc/cron.d/etigo-scheduler` (or `crontab -l -u deploy`) and `php artisan schedule:list`.

### Scheduled jobs

Times are Africa/Lagos where noted, otherwise server time (UTC).

| Schedule | Job | Purpose |
|----------|-----|---------|
| Every 5 min | `ExpireEvReservationsJob` | Expire EV stall reservations not activated within 30 min; hand the stall to the next queued driver |
| Every 15 min | `ExpireAbandonedTopupsJob` | Mark top-ups never paid as abandoned |
| Every 15 min | `ReconcilePayoutsJob` | Settle payouts whose transfer outcome is unknown (approved > 10 min, processing > 30 min) against Paystack |
| Every 30 min | `StuckTransactionSweeperJob` | Re-verify pending top-ups with Paystack and credit or abandon them |
| Hourly | `ExpireStaleHoldsJob` | Release expired wallet holds (after `hold_expiry_hours`) whose ride is no longer active — holds of rides still under way are kept |
| Hourly | `SettleDriverEarningsJob` | Move pending driver earnings to available (per the settlement-delay setting) |
| Hourly | `LiftExpiredComplianceSuspensionsJob` | Reinstate drivers whose automatic 48-hour suspension has ended |
| Daily 00:00 | `model:prune` (notifications) | Delete in-app notifications older than 90 days |
| Daily 02:00 | `DailyReconciliationJob` | Compare gateway top-ups/transfers with the ledger |
| Daily 02:30 | `ledger:check` | Ledger integrity check (balances vs entries) |
| Daily 04:00 Lagos | `DailyRemittanceSettlementJob` → `RemittanceShortfallAlertJob` (chained) | Settle yesterday's fleet remittances, then update shortfall flags |
| Monday 06:00 Lagos | `ProcessWeeklyAutoPayoutJob` | Weekly payouts for drivers who chose `weekly_auto` |
| Monday 08:00 | `NegativeDriverBalanceReportJob` | Report drivers with negative balances (cash commission debt) |

## Rollback

To rollback to a previous release, re-point the `current` symlink:

```bash
ssh deploy@<SERVER_IP>

# List available releases
ls -lt /var/www/etigo-api/releases/

# Switch to a previous release
ln -nfs /var/www/etigo-api/releases/<PREVIOUS_RELEASE> /var/www/etigo-api/current
sudo systemctl restart php8.4-fpm
sudo supervisorctl restart etigo-worker:*
```

The last 5 releases are kept on disk. Older releases are cleaned up automatically on each deploy.

## Server Provisioning

For provisioning a fresh server, use `deployment/provision.sh`. It installs all dependencies, creates the `deploy` user, configures Nginx/PHP-FPM/PostgreSQL/Redis/Supervisor/UFW/Fail2Ban.

```bash
# On a fresh Ubuntu 24.04 droplet as root:
scp deployment/provision.sh root@<SERVER_IP>:/tmp/provision.sh
ssh root@<SERVER_IP> "bash /tmp/provision.sh"
```

After provisioning, update:
1. PostgreSQL password: `sudo -u postgres psql -c "ALTER USER etigo PASSWORD '<password>';"`
2. Redis password: edit `requirepass` in `/etc/redis/redis.conf` and restart Redis
3. Create the shared `.env` at `/var/www/etigo-api/shared/.env`
4. Add the server's deploy key (`/home/deploy/.ssh/id_ed25519.pub`) as a GitHub deploy key

## Environment Configuration

The staging `.env` lives at `/var/www/etigo-api/shared/.env` and is symlinked into each release. Key settings:

```env
APP_NAME=Etigo
APP_ENV=staging
APP_DEBUG=false
DB_CONNECTION=pgsql
DB_DATABASE=etigo_staging
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=database
REDIS_SCHEME=tcp

# Mail — Resend SMTP
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.resend.com
MAIL_PORT=465
MAIL_USERNAME=resend
MAIL_PASSWORD=<your-resend-api-key>
MAIL_FROM_ADDRESS="noreply@etigo.com"
MAIL_FROM_NAME="${APP_NAME}"

# S3 — Driver document storage
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=eu-west-1
AWS_BUCKET=etigo-driver-documents
AWS_USE_PATH_STYLE_ENDPOINT=false
```

> **Important:** The `etigo.com` domain must be verified on [Resend](https://resend.com/domains) before emails will send in production. Add the DNS records (SPF, DKIM, DMARC) provided by Resend to your domain registrar.

To update environment variables:

```bash
ssh deploy@<SERVER_IP>
nano /var/www/etigo-api/shared/.env
# Then clear the config cache:
cd /var/www/etigo-api/current
php artisan config:cache
```

## Services Management

```bash
# Nginx
sudo systemctl restart nginx
sudo nginx -t                          # test config before restart

# PHP-FPM
sudo systemctl restart php8.4-fpm

# Queue Workers
sudo supervisorctl status etigo-worker:*
sudo supervisorctl restart etigo-worker:*

# Reverb (WebSockets)
php artisan reverb:restart

# Scheduler
cat /etc/cron.d/etigo-scheduler
php artisan schedule:list

# Redis
sudo systemctl restart redis-server

# PostgreSQL
sudo systemctl restart postgresql
```

## Logs

```bash
# Laravel application log
tail -f /var/www/etigo-api/shared/storage/logs/laravel.log

# Queue worker log
tail -f /var/www/etigo-api/shared/storage/logs/worker.log

# Nginx access/error logs
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log

# PHP-FPM error log
tail -f /var/log/php/etigo-error.log
```

## Security

- **Firewall (UFW)**: Only SSH (22) and HTTP/HTTPS (80/443) are open
- **Fail2Ban**: Active, protects against brute-force SSH attempts
- **Nginx headers**: X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy
- **PHP**: `X-Powered-By` header is hidden
- **File permissions**: App owned by `deploy:www-data`, storage is `775`


### Credentials

Database and Redis credentials are stored in the server's `.env` file and must not be committed to the repository. Rotate credentials via the hosting provider's dashboard or directly on the server, then update `.env` accordingly.
