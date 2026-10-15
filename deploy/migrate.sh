#!/bin/bash
set -euo pipefail
cd /srv/skladex/app
php artisan config:clear --no-interaction
set -a
source /etc/skladex/migrate.env
set +a
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
