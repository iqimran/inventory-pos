#!/usr/bin/env bash
# Back up the MySQL database and the Laravel storage volume.
#
#   docker/scripts/backup.sh                         # production (docker-compose.prod.yml)
#   COMPOSE_FILE=docker-compose.yml docker/scripts/backup.sh   # development stack
#
# Writes backups/<timestamp>-database.sql.gz and backups/<timestamp>-storage.tar.gz, verifies the
# dump is complete before keeping it, and deletes backups older than RETENTION_DAYS (default 14).
set -euo pipefail

cd "$(dirname "$0")/../.."
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
BACKUP_DIR="${BACKUP_DIR:-backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

stamp="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
BACKUP_DIR="$(cd "$BACKUP_DIR" && pwd)"

# A container that is "started" may still be initialising: wait until MySQL answers.
for _ in $(seq 1 30); do
    compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -uroot --silent' >/dev/null 2>&1 && break
    sleep 2
done

database="$BACKUP_DIR/$stamp-database.sql.gz"
# Whatever happens, never leave a half-written dump behind.
trap 'rm -f "$database.partial"' EXIT
compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' \
    | gzip > "$database.partial"

# Never keep a truncated or empty dump.
if ! gunzip -c "$database.partial" | grep -q 'CREATE TABLE' || ! gunzip -c "$database.partial" | tail -n 1 | grep -q 'Dump completed'; then
    rm -f "$database.partial"
    echo "Backup FAILED: the database dump is empty or incomplete." >&2
    exit 1
fi
mv "$database.partial" "$database"

# Uploaded files and logs live in the app's storage volume.
# The volume mounted at the app container's storage path (none in development, which bind-mounts).
app_container="$(compose ps -q app || true)"
storage_volume="$([ -n "$app_container" ] && docker inspect -f '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{{if eq .Type "volume"}}{{.Name}}{{end}}{{end}}{{end}}' "$app_container" || true)"
if [ -n "$storage_volume" ]; then
    docker run --rm -v "$storage_volume":/storage:ro -v "$BACKUP_DIR":/backup alpine \
        tar czf "/backup/$stamp-storage.tar.gz" -C /storage .
fi

find "$BACKUP_DIR" -name '*-database.sql.gz' -mtime +"$RETENTION_DAYS" -delete
find "$BACKUP_DIR" -name '*-storage.tar.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Backup OK: $database ($(du -h "$database" | cut -f1))${storage_volume:+ and $BACKUP_DIR/$stamp-storage.tar.gz}"
