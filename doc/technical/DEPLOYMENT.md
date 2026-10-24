# eTIGO API — Deployment Guide

## Infrastructure Overview

| Component   | Detail                          |
|-------------|---------------------------------|
| Provider    | DigitalOcean Droplet             |
| OS          | Ubuntu 24.04 LTS                 |
| PHP         | 8.5 (FPM)                       |
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

1. **Test Job** (PHP 8.5, the version the server and local development run): spins up PostgreSQL 16 and Redis 7 service containers, installs PHP with extensions, runs `composer install`, executes migrations and runs the full test suite. It must pass before deploying. `composer.json` pins `config.platform.php` to the server's 8.5.12, so `composer update` on a newer local PHP cannot lock packages the server cannot run.
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

sudo systemctl restart php8.5-fpm
sudo supervisorctl restart etigo-worker:*
php artisan reverb:restart
```

`ReferenceDataSeeder` is idempotent: it creates the ledger system accounts, the default commission rate, loyalty tiers and point multipliers only when they are missing, and never overwrites values an admin has changed. Payments, settlements and refunds fail without the system accounts, so it runs on every deploy.

## Background Processes

Three long-running pieces must be up for the platform to work, besides Nginx and PHP-FPM:

| Process | How it runs | What breaks without it |
|---------|-------------|------------------------|
| Queue workers | Supervisor `etigo-worker` (2 processes, `queue:work database`) | Driver matching and offer timeouts, final fare, push/in-app notifications, payout transfers, webhook processing, SOS check-ins and alerts |
| Scheduler | cron: `* * * * * deploy cd /var/www/etigo-api/current && php artisan schedule:run` (`/etc/cron.d/etigo-scheduler`) | Driver earnings settlement, wallet hold and top-up expiry, stuck top-up recovery, daily reconciliation and ledger check, weekly auto-payouts, fleet remittance settlement, scheduled-ride dispatch and reminders, fleet shifts, vehicle maintenance, EV reservation expiry, lifting 48-hour compliance suspensions, notification clean-up |
| Reverb | Supervisor `etigo-reverb` (`php artisan reverb:start`), proxied by Nginx | Live ride updates, driver offers over WebSocket and the admin SOS console (push notifications and polling endpoints still work) |
| Telemetry gateway (optional) | `php artisan telemetry:serve start` under Supervisor, port `TELEMETRY_SERVER_PORT` (8081) behind Nginx | High-frequency driver GPS over WebSocket. Without it drivers report location through `POST /driver/location`. `deploy.sh` restarts it (Supervisor `etigo-telemetry`) — it is long-running and would otherwise keep old code |

`provision.sh` sets up the queue workers and the scheduler, and every deploy (`deploy.sh`) installs the scheduler entry if it is missing and restarts the telemetry gateway. Check an existing server with `cat /etc/cron.d/etigo-scheduler` (or `crontab -l -u deploy`) and `php artisan schedule:list`.

### Scheduled jobs

Times are Africa/Lagos where noted, otherwise server time (UTC).

| Schedule | Job | Purpose |
|----------|-----|---------|
| Every minute | `ProcessScheduledRidesJob` | Scheduled rides: start matching (or hand the ride to its reserved driver) for bookings whose dispatch time has come, and send passenger and reserved-driver reminders (queues `ScheduledRideDispatchJob` / `ScheduledRideReminderJob`). Needs the scheduler **and** a queue worker; without them scheduled rides never start. Late-cancellation fees are collected by the queued `ChargeScheduledRideCancellationFeeJob` |
| Every minute (local and staging only) | `demo:simulate-drivers` | Keep the demo drivers from `MonitoringDemoSeeder` reporting their location (does nothing when none are seeded) |
| Every 5 min | `ExpireEvReservationsJob` | Expire EV stall reservations not activated within 30 min; hand the stall to the next queued driver |
| Every 5 min | `ProcessDriverShiftsJob` | Shared fleet cars: mark shifts not started after 30 min as missed and email operations, tell drivers when offers stop and when the car is late, end shifts (set the driver offline unless on a trip) |
| Every 15 min | `ExpireAbandonedTopupsJob` | Mark top-ups never paid as abandoned |
| Every 15 min | `ReconcilePayoutsJob` | Settle payouts whose transfer outcome is unknown (approved > 10 min, processing > 30 min) against Paystack |
| Every 15 min | `ProcessDisputeTargetsJob` | Disputes: stamp cases that missed a response target; move tier 1 misses to tier 2 and email super admins about Safety and tier 2 misses (the job itself and its emails are queued, so both need a queue worker) |
| Every 30 min | `StuckTransactionSweeperJob` | Re-verify pending top-ups with Paystack and credit or abandon them |
| Hourly | `ExpireStaleHoldsJob` | Release expired wallet holds (after `hold_expiry_hours`) whose ride is no longer active — holds of rides still under way are kept |
| Hourly | `SettleDriverEarningsJob` | Move pending driver earnings to available (per the settlement-delay setting) |
| Hourly | `LiftExpiredComplianceSuspensionsJob` | Reinstate drivers whose automatic 48-hour suspension has ended |
| Hourly | `ProcessLostItemsJob` | Lost & Found: hand reports the driver has not answered (24 h) and overdue returns (7 days) to support, resolve undisputed returns (72 h), close stale "not found" reports (7 days), retry pending return fees |
| Daily 00:00 | `model:prune` (notifications) | Delete in-app notifications older than 90 days |
| Daily 02:00 | `DailyReconciliationJob` | Compare gateway top-ups/transfers with the ledger |
| Daily 02:30 | `ledger:check` | Ledger integrity check (balances vs entries) |
| Daily 06:00 Lagos | `ProcessVehicleMaintenanceJob` | Vehicle maintenance: recompute every car's items, remind drivers once per change, keep cars whose insurance / roadworthiness / vehicle licence expired more than 7 days ago off the road (or lift it), email operations a digest of fleet cars needing work |
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
sudo systemctl restart php8.5-fpm
sudo supervisorctl restart etigo-worker:*
```

The last 5 releases are kept on disk. Older releases are cleaned up automatically on each deploy.

## Server Provisioning

For provisioning a fresh server, use `deployment/provision.sh`. It installs all dependencies, creates the `deploy` user, configures Nginx/PHP-FPM/PostgreSQL/Redis/Supervisor/UFW/Fail2Ban.

```bash
# On a fresh Ubuntu 24.04 droplet as root:
scp deployment/provision.sh deployment/php-auto-update.sh root@<SERVER_IP>:/tmp/
ssh root@<SERVER_IP> "bash /tmp/provision.sh"
```

`provision.sh` runs `php-auto-update.sh` from the same folder. That script lets Ubuntu's unattended-upgrades install PHP from the ondrej PPA (Ubuntu's default skips it) and adds an apt hook that restarts any supervisor program still running a replaced PHP binary, so Reverb and the telemetry gateway pick up a PHP security release without anyone watching. PHP-FPM is restarted by its own package, and queue workers also recycle hourly. Restarts are logged to syslog under `etigo-restart-stale-php`. On an existing server, run it on its own:

```bash
ssh root@<SERVER_IP> 'bash -s' < deployment/php-auto-update.sh
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

### Paystack dashboard settings

Set these in **both** Test mode (staging) and Live mode (production); each mode has its own settings.

| Setting | Value | Why |
|---------|-------|-----|
| Webhook URL (Settings → API Keys & Webhooks) | `https://<api-host>/api/v1/webhooks/paystack` (staging: `https://etigo-api.apeinhood.xyz/api/v1/webhooks/paystack`) | Credits top-ups and settles payouts as soon as Paystack confirms them. Without it, payouts wait for the 15-minute reconciliation |
| Transfer OTP (Settings → Preferences) | Off | With it on, every API transfer waits for an OTP and is abandoned; such payouts show `attention_reason: awaiting_otp` |
| Transfer Approval → Approval URL (Settings → Preferences) | Tick **Confirm transfers in live mode** (production) or **in test mode** (staging) and set `https://<api-host>/api/v1/webhooks/paystack/transfer-approval` (staging: `https://etigo-api.apeinhood.xyz/api/v1/webhooks/paystack/transfer-approval`). **On Reject:** Do Nothing. **Contact email:** a monitored finance/ops inbox | Paystack asks our API about every transfer before sending it, and only payouts the API is sending are approved. A leaked secret key cannot move money, and transfers made by hand in the dashboard are rejected too. If the API is down, payouts are blocked, then returned to the driver by reconciliation and can be retried. Run one staging payout and check that it is approved before turning this on in live mode |
| IP whitelist | The API server's public IP | A leaked secret key is useless from anywhere else |
| Dashboard two-factor sign-in | On for every user | Protects the account that can move money |

Keep only the float needed for upcoming payouts in the Paystack balance.

### One-off after deploying fuel types

Vehicles created before fuel type was required have none. Run once (and again whenever useful) to ask their drivers to set it and to list fleet vehicles operations must update:

```bash
php artisan vehicles:request-fuel-types --dry-run   # list only
php artisan vehicles:request-fuel-types
```

### After deploying multi-city support (Phase 2)

The migration sets each city's `launch_status`: active cities become `live`, inactive cities that have had trips `paused`, and inactive cities with no trips `onboarding`. Check the result in the admin city list, then give each onboarding city its target `launch_date` once the client has set one. Nothing else is needed; `CITY_*` variables are optional (`.env.example`). The follow-up work of a launch or pause (cancelling bookings when asked, telling drivers and the waitlist) runs on the queue. The procedure for opening a new city is in `doc/technical/CITY_ONBOARDING.md`.

### After deploying driver scheduling and vehicle maintenance (Phase 2)

The migration links existing vehicle papers (vehicle registration, insurance) to each driver's car; papers it could not match are listed at `GET /admin/vehicle-documents/unlinked` for operations to link. Existing cars get their maintenance items on the first daily run (06:00 Lagos); to do it at once:

```bash
php artisan vehicles:check-maintenance
```

The first run sends every driver one push listing what their car needs (most cars have no service date or roadworthiness / vehicle licence on file yet). Missing papers only remind; only an **expired** paper past the 7-day grace period keeps a car off the road. Fleet cars to be shared on shifts need shift mode turned on (`PUT /admin/fleet-vehicles/{vehicle}/shift-mode`) before shifts can be assigned. Nothing else is needed; `DRIVER_SHIFT_*`, `DRIVER_AVAILABILITY_*` and `VEHICLE_MAINTENANCE_*` variables are optional (`.env.example`). Both jobs and their emails need the scheduler and a queue worker.

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
sudo systemctl restart php8.5-fpm

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

### Changing the PHP version

Staging moved from 8.4 to 8.5 this way on 24 Oct 2026. Install the new version next to the old one and keep the old one until the switch is checked.

1. Install the same packages as the old version: `dpkg -l 'php8.4*'` lists them (on 8.5, OPcache is built in and has no package).
2. Copy `/etc/php/<old>/fpm/pool.d/etigo.conf` to the new version with the new socket path, delete the new version's `www.conf`, and restart its FPM service.
3. Point `fastcgi_pass` at the new socket in `/etc/nginx/sites-enabled/etigo-api`. On staging this is its own file, not a link to `sites-available`, and it holds the long websocket timeouts. Run `nginx -t`, reload nginx and check `/api/v1/health`.
4. `sudo update-alternatives --set php /usr/bin/php<new>` moves the CLI, so the queue workers, Reverb, telemetry and the scheduler follow. Restart them with `sudo supervisorctl restart all`.
5. Update `PHP_VERSION` in `deployment/provision.sh`, `config.platform.php` in `composer.json` (then `composer update --lock`) and the CI matrix. The deploy script reloads every running FPM service, so it needs no change.
6. Once a deploy has gone through on the new version, stop and disable the old FPM service.

## Logs

The application log rotates daily: one file per day, and only the last 14 files are kept. That bounds how many days are kept, not the size of a single day's file. Set in `shared/.env`:

```env
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=info
```

Run `php artisan config:cache` after changing them (config is cached). Each day's file is created by whichever process logs first: the web, queue and telemetry processes run as `deploy` but Reverb runs as `www-data`, so the channel writes files group-writable (`0664`) and `shared/storage/logs` has the setgid bit (`chmod g+s`, group `www-data`; `provision.sh` and `deploy.sh` set it) so new files keep group `www-data`. Logs are for engineers on the server; they hold personal data and are never served over the API. Errors worth acting on belong in an error tracker (Sentry or Laravel Nightwatch), and problems admins must fix are shown to them in the app (city and pricing `warnings`, operations alert emails).

```bash
# Laravel application log (today's file; -F waits for it to be created)
tail -F /var/www/etigo-api/shared/storage/logs/laravel-$(date +%F).log

# API requests (one line each, LogRequests), also daily
tail -F /var/www/etigo-api/shared/storage/logs/requests-$(date +%F).log

# The last 50 errors and warnings across the kept files
grep -hE '\.(ERROR|CRITICAL|WARNING):' /var/www/etigo-api/shared/storage/logs/laravel-*.log | tail -50

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
