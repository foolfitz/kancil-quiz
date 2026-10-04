#!/bin/sh
# 從 docker/backup.sh 的備份還原（docs/deploy.md）。會覆蓋目前的資料庫與媒體，網站會停止幾秒。
#   docker/restore.sh <備份檔 kancil-*.tar.gz>
set -eu
file=${1:?用法：docker/restore.sh <備份檔>}
dir=$(cd "$(dirname "$file")" && pwd)
name=$(basename "$file")

docker compose stop app
docker compose run --rm --no-deps -T -v "$dir:/backups:ro" -e BACKUP="/backups/$name" \
    --entrypoint sh app -c '
    set -e
    rm -rf /tmp/restore && mkdir -p /tmp/restore
    tar -xzf "$BACKUP" -C /tmp/restore
    mkdir -p "$(dirname "$DB_DATABASE")"
    rm -f "$DB_DATABASE" "$DB_DATABASE-wal" "$DB_DATABASE-shm"
    mv /tmp/restore/kancil.sqlite "$DB_DATABASE"
    rm -rf /app/storage/app/public
    mv /tmp/restore/app/public /app/storage/app/public
'
docker compose start app
echo "已從 $file 還原"
