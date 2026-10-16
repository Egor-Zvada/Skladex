#!/bin/bash
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Run as root'; exit 1; }
app=/srv/skladex/app
[[ -f $app/artisan && -d $app/vendor ]] || exit 1
[[ ! -e $app/public/assets ]] || { echo 'Legacy public/assets shadows the equipment registry. Move it outside public before activation.'; exit 1; }
chgrp -R skladex "$app"
find "$app" -type d -exec chmod 0750 {} +
find "$app" -type f -exec chmod 0640 {} +
setfacl -m u:www-data:x "$app"
find "$app/public" -type d -exec chmod 0755 {} +
find "$app/public" -type f -exec chmod 0644 {} +
chown -R skladex:skladex "$app/storage" "$app/bootstrap/cache"
find "$app/storage" "$app/bootstrap/cache" -type d -exec chmod 0770 {} +
cd "$app"
# Run with service defaults, never with migration-owner credentials.
unset DB_USERNAME DB_PASSWORD DB_DATABASE DB_URL APP_ENV APP_KEY LICENSE_MASTER_KEY
runuser -u skladex -- php artisan config:cache
runuser -u skladex -- php artisan route:cache
runuser -u skladex -- php artisan view:cache
runuser -u skladex -- php artisan skladex:check
nginx -t
php-fpm8.4 -t
systemctl restart skladex-queue.service
systemctl reload php8.4-fpm nginx
