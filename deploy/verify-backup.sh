#!/bin/bash
set -euo pipefail
umask 077
[[ $(id -u) == 0 && $# == 1 ]] || { echo 'Usage (root): verify-backup.sh /var/lib/skladex/backups/skladex-TIMESTAMP.tar.gz'; exit 1; }
archive=$(realpath -- "$1")
[[ $archive == /var/lib/skladex/backups/skladex-*.tar.gz && -f $archive ]] || exit 1
stamp=$(basename "$archive" .tar.gz); stamp=${stamp#skladex-}
[[ $stamp =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || exit 1
keys=/var/lib/skladex/key-backups/skladex-keys-$stamp.tar.gz
exec 9>/run/lock/skladex-restore-check.lock
flock -n 9 || exit 1
sha256sum -c "$archive.sha256"
sha256sum -c "$keys.sha256"
temp=$(mktemp -d /var/lib/skladex/backups/.restore.XXXXXXXX)
created=0
cleanup() {
    if [[ $created == 1 ]]; then mariadb -e 'DROP DATABASE IF EXISTS skladex_restore_test'; fi
    rm -f "$temp/database.sql"
    rm -f "$temp/license-master.key"
    rm -f "$temp/app.env"
    rmdir "$temp"
}
trap cleanup EXIT
tar -xOzf "$archive" database.sql > "$temp/database.sql"
# Fixed, isolated name. Never restore over the running application database.
mariadb -e 'CREATE DATABASE skladex_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
created=1
mariadb skladex_restore_test < "$temp/database.sql"
mariadb-check --check skladex_restore_test
while IFS=$'\t' read -r path expected; do
    [[ $path =~ ^documents/[a-f0-9-]{36}\.[a-z0-9]+$ && $expected =~ ^[a-f0-9]{64}$ ]] || { echo 'Unexpected document path in backup'; exit 1; }
    actual=$(tar -xOzf "$archive" "storage/app/private/$path" | sha256sum | cut -d' ' -f1)
    [[ $actual == "$expected" ]] || { echo 'Document checksum mismatch'; exit 1; }
done < <(mariadb -N skladex_restore_test -e 'SELECT path,sha256 FROM documents')
tar -xOzf "$keys" etc/skladex/license-master.key > "$temp/license-master.key"
tar -xOzf "$keys" etc/skladex/app.env > "$temp/app.env"
cd /srv/skladex/app
php artisan skladex:verify-restored-keys "$temp/license-master.key" --app-env="$temp/app.env"
mariadb -N skladex_restore_test -e 'SELECT "assets",COUNT(*) FROM assets UNION ALL SELECT "categories",COUNT(*) FROM categories UNION ALL SELECT "users",COUNT(*) FROM users'
echo 'Restore check passed; temporary database will be removed.'
