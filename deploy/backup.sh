#!/bin/bash
set -euo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo 'Run as root'; exit 1; }
exec 9>/run/lock/skladex-backup.lock
flock -n 9 || exit 0
install -d -m 0700 /var/lib/skladex/backups /var/lib/skladex/key-backups
stamp=$(date -u +%Y%m%dT%H%M%SZ)
temp=$(mktemp -d /var/lib/skladex/backups/.build.XXXXXXXX)
trap 'rm -f "$temp/database.sql"; rmdir "$temp"' EXIT
mariadb-dump --single-transaction --quick --routines --triggers --hex-blob --default-character-set=utf8mb4 skladex > "$temp/database.sql"
data=/var/lib/skladex/backups/skladex-$stamp.tar.gz
tar -czf "$data.partial" -C "$temp" database.sql -C /srv/skladex/app storage/app/private
gzip -t "$data.partial"
mv "$data.partial" "$data"
sha256sum "$data" > "$data.sha256"
# Database/files and decryption material are deliberately separate archives.
keys=/var/lib/skladex/key-backups/skladex-keys-$stamp.tar.gz
tar -czf "$keys.partial" -C / etc/skladex
gzip -t "$keys.partial"
mv "$keys.partial" "$keys"
sha256sum "$keys" > "$keys.sha256"
find /var/lib/skladex/backups /var/lib/skladex/key-backups -maxdepth 1 -type f -name 'skladex-*.tar.gz*' -mtime +14 -delete
echo "Backup created: $data (keys stored separately)"
