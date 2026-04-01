#!/bin/bash
# bin/backup.sh
# Creates a timestamped PostgreSQL dump of the BCC database.
#
# Usage (from project root):
#   bash bin/backup.sh
#
# Backups are written to backups/ in the project root.
# Old backups are NOT automatically removed — clean up manually when needed.

set -euo pipefail

CONTAINER="${BCC_DB_CONTAINER:-local-bcc-db-1}"
DB_USER="${BCC_DB_USER:-bcc}"
DB_NAME="${BCC_DB_NAME:-bcc}"
BACKUP_DIR="$(cd "$(dirname "$0")/.." && pwd)/backups"
TIMESTAMP=$(date +"%Y%m%d-%H%M%S")
OUTFILE="${BACKUP_DIR}/bcc-${TIMESTAMP}.sql"

mkdir -p "$BACKUP_DIR"

echo "Backing up ${DB_NAME} from ${CONTAINER}..."
docker exec "$CONTAINER" pg_dump -U "$DB_USER" "$DB_NAME" > "$OUTFILE"

SIZE=$(du -sh "$OUTFILE" | cut -f1)
echo "Done: backups/bcc-${TIMESTAMP}.sql (${SIZE})"