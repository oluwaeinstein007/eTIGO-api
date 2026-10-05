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
| Process Mgr | Supervisor (queue workers)       |
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

1. **Test Job**: Spins up PostgreSQL 16 and Redis 7 service containers, installs PHP 8.4 with extensions, runs `composer install`, executes migrations, and runs the full test suite.
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
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link
ln -nfs ${RELEASE_DIR} ${APP_DIR}/current
'

sudo systemctl restart php8.4-fpm
sudo supervisorctl restart etigo-worker:*
```

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
APP_ENV=staging
APP_DEBUG=false
DB_CONNECTION=pgsql
DB_DATABASE=etigo_staging
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=database
REDIS_SCHEME=tcp
```

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


Credentials (saved in scratchpad for this session):

DB user: etigo / password: xjOLzAqmd9E2LORQLyhfoRUv
Redis password: ZQktVYS7GLFSasS3bZf8goG5
