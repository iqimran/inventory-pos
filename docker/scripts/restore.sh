#!/usr/bin/env bash
# Restore the database (and optionally the storage volume) from backups made by backup.sh.
#
#   docker/scripts/restore.sh backups/20260928-020000-database.sql.gz [backups/20260928-020000-storage.tar.gz]
#
# DESTRUCTIVE: replaces the current database contents. Take a fresh backup first.
set -euo pipefail

cd "$(dirname "$0")/../.."
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

database="${1:?Usage: restore.sh <database.sql.gz> [storage.tar.gz]}"
storage="${2:-}"

gunzip -t "$database"
gunzip -c "$database" | grep -q 'CREATE TABLE' || { echo "Not a database dump: $database" >&2; exit 1; }

if [ "${FORCE:-}" != "yes" ]; then
    read -r -p "Replace the database with $database? Type 'yes' to continue: " answer
    [ "$answer" = "yes" ] || { echo "Aborted."; exit 1; }
fi

gunzip -c "$database" | compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"'
echo "Database restored from $database"

if [ -n "$storage" ]; then
    storage_volume="$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{{if eq .Type "volume"}}{{.Name}}{{end}}{{end}}{{end}}' "$(compose ps -q app)")"
    [ -n "$storage_volume" ] || { echo "No storage volume on the app container." >&2; exit 1; }
    docker run --rm -v "$storage_volume":/storage -v "$(cd "$(dirname "$storage")" && pwd)":/backup:ro alpine \
        sh -c "tar xzf /backup/$(basename "$storage") -C /storage"
    echo "Storage restored from $storage"
fi

# Cached balances are rebuilt from nothing; confirm the restored ledgers are consistent.
compose exec -T app php artisan inventory:reconcile
compose exec -T app php artisan ledger:reconcile
