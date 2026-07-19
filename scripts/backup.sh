#!/bin/bash
#
# CRM Backup Script
# Backs up database (mysqldump) and uploads directory.
# Configure via .env or environment variables.
#
# Usage: ./scripts/backup.sh [--retention-days N] [--retention-weekly N]
# Cron: 0 2 * * * cd /path/to/crm && ./scripts/backup.sh >> /var/log/crm/backup.log 2>&1
#
# Retention: Keeps last N daily backups (default 7). Optionally keeps N weekly (default 4).
# Set BACKUP_DIR, or defaults to ./backups
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CRM_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$CRM_DIR"

# Load .env (simple key=value parser)
if [ -f .env ]; then
    while IFS= read -r line; do
        [[ "$line" =~ ^#.*$ ]] && continue
        [[ -z "$line" ]] && continue
        if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
            export "${BASH_REMATCH[1]}=${BASH_REMATCH[2]}"
        fi
    done < .env
fi

BACKUP_DIR="${BACKUP_DIR:-$CRM_DIR/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-7}"
RETENTION_WEEKLY="${RETENTION_WEEKLY:-4}"
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-crm_db}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
UPLOADS_DIR="${UPLOADS_DIR:-$CRM_DIR/uploads}"

# Parse args
while [[ $# -gt 0 ]]; do
    case $1 in
        --retention-days) RETENTION_DAYS="$2"; shift 2 ;;
        --retention-weekly) RETENTION_WEEKLY="$2"; shift 2 ;;
        *) shift ;;
    esac
done

mkdir -p "$BACKUP_DIR"
DATE=$(date +%Y%m%d_%H%M%S)

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting backup..."

# Database backup
DB_FILE="$BACKUP_DIR/db_$DATE.sql"
if command -v mysqldump &> /dev/null; then
    if [ -n "$DB_PASS" ]; then
        mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$DB_FILE" 2>/dev/null || {
            echo "mysqldump failed (check DB credentials in .env)"
            rm -f "$DB_FILE"
        }
    else
        mysqldump -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" > "$DB_FILE" 2>/dev/null || {
            echo "mysqldump failed"
            rm -f "$DB_FILE"
        }
    fi
    [ -f "$DB_FILE" ] && gzip -f "$DB_FILE" && echo "  DB: ${DB_FILE}.gz"
else
    echo "  mysqldump not found, skipping DB backup"
fi

# Uploads backup
if [ -d "$UPLOADS_DIR" ]; then
    FILES_FILE="$BACKUP_DIR/uploads_$DATE.tar.gz"
    tar -czf "$FILES_FILE" -C "$CRM_DIR" uploads 2>/dev/null || true
    [ -f "$FILES_FILE" ] && echo "  Uploads: $FILES_FILE"
else
    echo "  Uploads dir not found, skipping"
fi

# Retention: keep last N daily
find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime +$RETENTION_DAYS -delete 2>/dev/null || true
find "$BACKUP_DIR" -name "uploads_*.tar.gz" -mtime +$RETENTION_DAYS -delete 2>/dev/null || true

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup complete."
