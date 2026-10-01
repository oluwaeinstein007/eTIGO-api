#!/usr/bin/env bash
set -euo pipefail

# eTIGO API — Server Provisioning Script for Ubuntu 24.04
# Run as root on a fresh DigitalOcean droplet

export DEBIAN_FRONTEND=noninteractive

echo "==> Updating system packages..."
apt-get update && apt-get upgrade -y

echo "==> Installing core dependencies..."
apt-get install -y \
    curl wget git unzip software-properties-common \
    ufw fail2ban supervisor acl

echo "==> Adding PHP 8.3 repository..."
add-apt-repository -y ppa:ondrej/php
apt-get update

echo "==> Installing PHP 8.3 and extensions..."
apt-get install -y \
    php8.3-fpm php8.3-cli php8.3-common \
    php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-bcmath php8.3-intl php8.3-readline \
    php8.3-redis php8.3-gd php8.3-tokenizer

echo "==> Installing Nginx..."
apt-get install -y nginx

echo "==> Installing PostgreSQL 16..."
apt-get install -y postgresql-16 postgresql-client-16

echo "==> Installing Redis..."
apt-get install -y redis-server

echo "==> Installing Composer..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo "==> Creating deploy user..."
if ! id "deploy" &>/dev/null; then
    useradd -m -s /bin/bash -G www-data deploy
    mkdir -p /home/deploy/.ssh
    cp /root/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
    chown -R deploy:deploy /home/deploy/.ssh
    chmod 700 /home/deploy/.ssh
    chmod 600 /home/deploy/.ssh/authorized_keys
    echo "deploy ALL=(ALL) NOPASSWD: ALL" > /etc/sudoers.d/deploy
fi

echo "==> Setting up application directory..."
mkdir -p /var/www/etigo-api/shared/storage/{app/public,framework/{cache,sessions,testing,views},logs}
mkdir -p /var/www/etigo-api/releases
chown -R deploy:www-data /var/www/etigo-api
chmod -R 775 /var/www/etigo-api/shared/storage

echo "==> Configuring PostgreSQL..."
sudo -u postgres psql -c "SELECT 1 FROM pg_roles WHERE rolname='etigo'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE USER etigo WITH PASSWORD 'CHANGE_ME_STRONG_PASSWORD';"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='etigo_staging'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE DATABASE etigo_staging OWNER etigo;"

echo "==> Configuring Redis..."
sed -i 's/^# requirepass .*/requirepass CHANGE_ME_REDIS_PASSWORD/' /etc/redis/redis.conf
sed -i 's/^supervised no/supervised systemd/' /etc/redis/redis.conf
systemctl restart redis-server

echo "==> Configuring Nginx..."
cat > /etc/nginx/sites-available/etigo-api << 'NGINX'
server {
    listen 80;
    server_name _;
    root /var/www/etigo-api/current/public;

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
}
NGINX

ln -sf /etc/nginx/sites-available/etigo-api /etc/nginx/sites-enabled/etigo-api
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl restart nginx

echo "==> Configuring PHP-FPM pool..."
cat > /etc/php/8.3/fpm/pool.d/etigo.conf << 'PHPFPM'
[etigo]
user = deploy
group = www-data
listen = /var/run/php/php8.3-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500

php_admin_value[error_log] = /var/log/php/etigo-error.log
php_admin_flag[log_errors] = on
PHPFPM

rm -f /etc/php/8.3/fpm/pool.d/www.conf
mkdir -p /var/log/php
systemctl restart php8.3-fpm

echo "==> Configuring Laravel queue worker (Supervisor)..."
cat > /etc/supervisor/conf.d/etigo-worker.conf << 'SUPERVISOR'
[program:etigo-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/etigo-api/current/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deploy
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/etigo-api/shared/storage/logs/worker.log
stopwaitsecs=3600
SUPERVISOR

supervisorctl reread
supervisorctl update

echo "==> Configuring UFW firewall..."
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo "==> Configuring Fail2Ban..."
systemctl enable fail2ban
systemctl start fail2ban

echo "========================================"
echo "  Server provisioning complete!"
echo "========================================"
echo ""
echo "IMPORTANT — Change these before deploying:"
echo "  1. PostgreSQL password: sudo -u postgres psql -c \"ALTER USER etigo PASSWORD 'your_real_password';\""
echo "  2. Redis password: edit /etc/redis/redis.conf"
echo "  3. Create .env at /var/www/etigo-api/shared/.env"
echo ""
echo "Test with: curl http://$(curl -s ifconfig.me)"
