#!/bin/bash
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Run as root'; exit 1; }
app=/srv/skladex/app
id skladex >/dev/null 2>&1 || useradd --system --user-group --home /srv/skladex --shell /usr/sbin/nologin skladex
[[ -f "$app/artisan" && -d "$app/vendor" ]] || { echo 'Deploy application and composer dependencies first'; exit 1; }
install -d -m 0750 -o root -g skladex /etc/skladex
install -d -m 0700 -o root -g root /etc/skladex/tls /var/lib/skladex/backups /var/lib/skladex/key-backups
for file in database-password migration-password test-password redis-password; do
    if [[ ! -s /etc/skladex/$file ]]; then openssl rand -hex 32 > "/etc/skladex/$file"; fi
    chmod 0600 "/etc/skladex/$file"
done
if [[ ! -s /etc/skladex/license-master.key ]]; then printf 'base64:' > /etc/skladex/license-master.key; openssl rand -base64 32 >> /etc/skladex/license-master.key; fi
chown root:skladex /etc/skladex/license-master.key; chmod 0640 /etc/skladex/license-master.key
app_pass=$(cat /etc/skladex/database-password)
owner_pass=$(cat /etc/skladex/migration-password)
test_pass=$(cat /etc/skladex/test-password)
redis_pass=$(cat /etc/skladex/redis-password)
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS skladex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS skladex_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'skladex_app'@'localhost' IDENTIFIED BY '$app_pass';
CREATE USER IF NOT EXISTS 'skladex_owner'@'localhost' IDENTIFIED BY '$owner_pass';
CREATE USER IF NOT EXISTS 'skladex_test'@'localhost' IDENTIFIED BY '$test_pass';
GRANT SELECT, INSERT, UPDATE, DELETE ON skladex.* TO 'skladex_app'@'localhost';
GRANT ALL PRIVILEGES ON skladex.* TO 'skladex_owner'@'localhost';
GRANT ALL PRIVILEGES ON skladex_test.* TO 'skladex_test'@'localhost';
SQL
if [[ ! -s /etc/skladex/app.env ]]; then
    app_key="base64:$(openssl rand -base64 32)"
    setup_token=$(openssl rand -hex 32)
    setup_hash=$(printf '%s' "$setup_token" | sha256sum | cut -d' ' -f1)
    cat > /etc/skladex/app.env <<ENV
APP_NAME=Skladex
APP_ENV=production
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=https://skladex.vve.local
APP_ALLOWED_HOSTS=skladex.vve.local,192.168.110.203,localhost,127.0.0.1
APP_LOCALE=ru
APP_FALLBACK_LOCALE=en
SKLADEX_TIMEZONE=Asia/Sakhalin
QR_BASE_URL=https://skladex.vve.local
QR_ALLOWED_HOSTS=skladex.vve.local,192.168.110.200,192.168.110.203,localhost
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_SOCKET=/run/mysqld/mysqld.sock
DB_DATABASE=skladex
DB_USERNAME=skladex_app
DB_PASSWORD=$app_pass
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=skladex_v2_session
SESSION_SAME_SITE=lax
CACHE_STORE=redis
CACHE_PREFIX=skladex_cache_
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=180
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=$redis_pass
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
LOG_CHANNEL=daily
LOG_LEVEL=notice
LOG_DAILY_DAYS=14
MAIL_MAILER=log
LICENSE_MASTER_KEY_FILE=/etc/skladex/license-master.key
LICENSE_KEY_ID=v1
SETUP_TOKEN_HASH=$setup_hash
ENV
    printf 'https://skladex.vve.local/setup#token=%s\n' "$setup_token" > /etc/skladex/setup-link
    chown root:codex-maint /etc/skladex/setup-link; chmod 0640 /etc/skladex/setup-link
fi
if [[ -f /etc/skladex/setup-link ]]; then
    sed -i 's|https://192.168.110.203/setup?token=|https://skladex.vve.local/setup#token=|' /etc/skladex/setup-link
    chown root:codex-maint /etc/skladex/setup-link; chmod 0640 /etc/skladex/setup-link
fi
chown root:skladex /etc/skladex/app.env; chmod 0640 /etc/skladex/app.env
ln -sfn /etc/skladex/app.env "$app/.env"
printf 'DB_USERNAME=skladex_owner\nDB_PASSWORD=%s\n' "$owner_pass" > /etc/skladex/migrate.env
printf 'DB_USERNAME=skladex_test\nDB_PASSWORD=%s\nDB_DATABASE=skladex_test\n' "$test_pass" > /etc/skladex/test.env
chown root:codex-maint /etc/skladex/migrate.env /etc/skladex/test.env; chmod 0640 /etc/skladex/migrate.env /etc/skladex/test.env
cat > /etc/mysql/mariadb.conf.d/60-skladex.cnf <<'DB'
[mysqld]
bind-address=127.0.0.1
innodb_buffer_pool_size=512M
max_connections=50
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
DB
cat > /etc/redis/skladex.conf <<REDIS
bind 127.0.0.1 ::1
protected-mode yes
port 6379
requirepass $redis_pass
daemonize no
supervised systemd
dir /var/lib/redis
logfile /var/log/redis/redis-server.log
pidfile /run/redis/redis-server.pid
appendonly yes
appendfsync everysec
maxmemory 128mb
maxmemory-policy noeviction
REDIS
chown root:redis /etc/redis/skladex.conf; chmod 0640 /etc/redis/skladex.conf
install -d /etc/systemd/system/redis-server.service.d
cat > /etc/systemd/system/redis-server.service.d/skladex.conf <<'REDISUNIT'
[Service]
ExecStart=
ExecStart=/usr/bin/redis-server /etc/redis/skladex.conf
REDISUNIT
cat > /etc/php/8.4/fpm/pool.d/skladex.conf <<'FPM'
[skladex]
user=skladex
group=skladex
listen=/run/php/skladex.sock
listen.owner=www-data
listen.group=www-data
listen.mode=0660
pm=dynamic
pm.max_children=6
pm.start_servers=2
pm.min_spare_servers=1
pm.max_spare_servers=3
pm.max_requests=500
request_terminate_timeout=120s
php_admin_value[memory_limit]=256M
php_admin_value[upload_max_filesize]=10M
php_admin_value[post_max_size]=12M
php_admin_flag[expose_php]=off
php_admin_flag[display_errors]=off
FPM
cat > /etc/php/8.4/fpm/conf.d/99-skladex.ini <<'INI'
opcache.enable=1
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
session.use_strict_mode=1
INI
if [[ ! -s /etc/skladex/tls/private.key ]]; then
    openssl req -x509 -newkey rsa:3072 -nodes -days 30 -subj '/CN=skladex.vve.local' -addext 'subjectAltName=DNS:skladex.vve.local,IP:192.168.110.203' -keyout /etc/skladex/tls/private.key -out /etc/skladex/tls/fullchain.pem >/dev/null 2>&1
    touch /etc/skladex/tls/SELF_SIGNED_TEMPORARY
fi
chmod 0600 /etc/skladex/tls/private.key
cp "$app/deploy/nginx.conf" /etc/nginx/sites-available/skladex
ln -sfn /etc/nginx/sites-available/skladex /etc/nginx/sites-enabled/skladex
if [[ -L /etc/nginx/sites-enabled/default ]]; then unlink /etc/nginx/sites-enabled/default; fi
cp "$app/deploy/skladex-queue.service" "$app/deploy/skladex-scheduler.service" "$app/deploy/skladex-scheduler.timer" /etc/systemd/system/
cp "$app/deploy/skladex-backup.service" "$app/deploy/skladex-backup.timer" /etc/systemd/system/
chgrp -R skladex "$app"
find "$app" -type d -exec chmod 0750 {} +
find "$app" -type f -exec chmod 0640 {} +
setfacl -m u:www-data:x "$app"
find "$app/public" -type d -exec chmod 0755 {} +
find "$app/public" -type f -exec chmod 0644 {} +
install -d -o skladex -g skladex -m 0770 "$app/storage/logs" "$app/storage/framework/cache/data" "$app/storage/framework/sessions" "$app/storage/framework/views" "$app/storage/app/private" "$app/bootstrap/cache"
chown -R skladex:skladex "$app/storage" "$app/bootstrap/cache"
usermod -a -G skladex codex-maint
nginx -t
php-fpm8.4 -t
systemctl daemon-reload
systemctl restart mariadb redis-server php8.4-fpm
systemctl enable --now nginx skladex-queue.service skladex-scheduler.timer skladex-backup.timer
systemctl reload nginx
echo 'Infrastructure configured. Apply migrations with the owner credentials, then cache configuration.'
