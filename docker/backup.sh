#!/bin/sh
# 備份資料庫與上傳的媒體（docs/deploy.md）。在 repo 的目錄執行：
#   docker/backup.sh <備份目錄> [保留份數，預設 14]
# SQLite 用 .backup 取得一致的快照，網站不必停止。
set -eu
dest=${1:?用法：docker/backup.sh <備份目錄> [保留份數]}
keep=${2:-14}
mkdir -p "$dest"
file="$dest/kancil-$(date +%Y%m%d-%H%M%S).tar.gz"

docker compose exec -T app sh -c '
    set -e
    rm -rf /tmp/backup && mkdir -p /tmp/backup
    sqlite3 "$DB_DATABASE" ".backup /tmp/backup/kancil.sqlite"
    tar -czf - -C /tmp/backup kancil.sqlite -C /app/storage app/public
    rm -rf /tmp/backup
' >"$file.part"
mv "$file.part" "$file"
echo "$file"

# 只保留最近的幾份
ls -1t "$dest"/kancil-*.tar.gz | tail -n +"$((keep + 1))" | while read -r old; do
    rm -f -- "$old"
done
