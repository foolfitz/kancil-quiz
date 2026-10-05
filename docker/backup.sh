#!/bin/sh
# 備份資料庫與上傳的媒體（docs/deploy.md）。在 repo 的目錄執行：
#   docker/backup.sh <備份目錄> [保留份數，預設 14]
# SQLite 用 .backup 取得一致的快照，網站不必停止。
# 不論成功或失敗，結果（時間、大小、檔名）都寫進 volume 的 storage/app/private/status/backup.json，
# 後台的「系統狀態」頁會顯示（App\Support\SystemStatus）。
set -eu
dest=${1:?用法：docker/backup.sh <備份目錄> [保留份數]}
keep=${2:-14}
mkdir -p "$dest"
file="$dest/kancil-$(date +%Y%m%d-%H%M%S).tar.gz"

# 結束時（包括 set -e 中途失敗）把結果寫進容器。寫不進去（例如容器沒有啟動）不影響備份本身
ok=false
step=開始
json_string() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}
record() {
    now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    if [ "$ok" = true ]; then
        bytes=$(wc -c <"$file" | tr -d ' ')
        count=$(ls -1 "$dest"/kancil-*.tar.gz | wc -l | tr -d ' ')
        json=$(printf '{"at":"%s","ok":true,"file":"%s","bytes":%s,"kept":%s}' \
            "$now" "$(json_string "$file")" "$bytes" "$count")
    else
        rm -f -- "$file.part"
        json=$(printf '{"at":"%s","ok":false,"error":"%s"}' \
            "$now" "$(json_string "${step}失敗，見主機上的 backup.log")")
    fi
    printf '%s\n' "$json" | docker compose exec -T app sh -c \
        'mkdir -p /app/storage/app/private/status && cat >/app/storage/app/private/status/backup.json' || true
}
trap record EXIT

step=備份資料庫與媒體
docker compose exec -T app sh -c '
    set -e
    rm -rf /tmp/backup && mkdir -p /tmp/backup
    sqlite3 "$DB_DATABASE" ".backup /tmp/backup/kancil.sqlite"
    tar -czf - -C /tmp/backup kancil.sqlite -C /app/storage app/public
    rm -rf /tmp/backup
' >"$file.part"
step=搬移備份檔
mv "$file.part" "$file"
echo "$file"

# 只保留最近的幾份
step=清除舊備份
ls -1t "$dest"/kancil-*.tar.gz | tail -n +"$((keep + 1))" | while read -r old; do
    rm -f -- "$old"
done
ok=true
