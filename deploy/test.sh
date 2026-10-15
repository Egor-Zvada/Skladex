#!/bin/bash
set -euo pipefail
cd /srv/skladex/app
[[ ! -f bootstrap/cache/config.php ]] || { echo 'Use a separate checkout for tests when production config is cached.'; exit 1; }
set -a
source /etc/skladex/test.env
export APP_ENV=testing DB_CONNECTION=mariadb CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
set +a
[[ $DB_DATABASE == *_test ]] || { echo 'Refusing non-test database'; exit 1; }
sudo -n -u skladex --preserve-env=APP_ENV,DB_CONNECTION,DB_USERNAME,DB_PASSWORD,DB_DATABASE,CACHE_STORE,SESSION_DRIVER,QUEUE_CONNECTION /usr/bin/php vendor/bin/phpunit --colors=never "$@"
