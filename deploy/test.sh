#!/bin/bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
[[ ! -f bootstrap/cache/config.php ]] || { echo 'Use a separate checkout for tests when production config is cached.'; exit 1; }
set -a
source /etc/skladex/test.env
export APP_ENV=testing DB_CONNECTION=mariadb CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export APP_KEY="base64:$(openssl rand -base64 32)"
export LICENSE_MASTER_KEY="base64:$(openssl rand -base64 32)"
export DB_URL='' DB_HOST=localhost DB_SOCKET=/run/mysqld/mysqld.sock MAIL_MAILER=array SESSION_SECURE_COOKIE=false
set +a
[[ $DB_DATABASE == *_test ]] || { echo 'Refusing non-test database'; exit 1; }
sudo -n -u skladex --preserve-env=APP_ENV,APP_KEY,LICENSE_MASTER_KEY,DB_CONNECTION,DB_USERNAME,DB_PASSWORD,DB_DATABASE,DB_URL,DB_HOST,DB_SOCKET,CACHE_STORE,SESSION_DRIVER,SESSION_SECURE_COOKIE,QUEUE_CONNECTION,MAIL_MAILER /usr/bin/php vendor/bin/phpunit --colors=never "$@"
