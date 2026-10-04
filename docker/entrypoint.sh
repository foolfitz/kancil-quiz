#!/bin/sh
# 容器啟動（docs/deploy.md）：啟動網站前先準備資料目錄、快取設定、套用 migration。
# 其他指令（例如 docker compose run --rm app php artisan key:generate --show）直接執行。
set -e

case "$1" in
    -* | frankenphp)
        if [ -z "$APP_KEY" ]; then
            echo '缺少 APP_KEY：執行 docker compose run --rm app php artisan key:generate --show，填進 .env.production。' >&2
            exit 1
        fi

        # /app/storage 是 volume：第一次啟動時建立需要的目錄與 SQLite 檔
        mkdir -p "$(dirname "$DB_DATABASE")" storage/app/public storage/app/private storage/logs \
            storage/framework/cache/data storage/framework/sessions storage/framework/views
        [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"

        php artisan optimize
        php artisan migrate --force
        ;;
esac

exec docker-php-entrypoint "$@"
