#!/bin/sh
set -eu

COMPOSE_FILES="-f docker-compose.yml -f docker-compose.prod.yml"
BACKUP_DIR="/opt/backups/smeta"

mkdir -p "$BACKUP_DIR"

TS=$(date +%F_%H-%M-%S)
FILE="$BACKUP_DIR/smeta_${TS}.sql"

docker compose $COMPOSE_FILES exec -T db sh -lc \
  'mysqldump --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  > "$FILE"

gzip -f "$FILE"

find "$BACKUP_DIR" -type f -name "smeta_*.sql.gz" -mtime +14 -delete

echo "Backup created: ${FILE}.gz"
