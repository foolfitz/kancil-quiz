# syntax=docker/dockerfile:1
# 正式環境的映像檔（docs/deploy.md）：FrankenPHP 內建 Caddy，設定網域就自動取得 HTTPS 憑證。
# 建置前要先取得遊戲的 submodule：git submodule update --init

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4-trixie

FROM ${PHP_IMAGE} AS base
# 文字處理要 intl、媒體轉檔要 gd（WebP）與 ffmpeg、匯出要 zip（docs/SPEC.md 第 8、9 節）；
# sqlite3 用來做一致的資料庫備份
RUN install-php-extensions intl gd zip opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg sqlite3 \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /app

# 建置前端。wayfinder 的 Vite 外掛會呼叫 artisan，所以這一層也要 PHP 與 vendor。
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
ENV VITE_APP_NAME="Kancil Quiz"

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY package.json package-lock.json .npmrc ./
COPY packages packages
RUN npm ci --no-audit --no-fund
COPY . .
# dump-autoload 會執行 package:discover 與 filament:upgrade（發布 Filament 的前端檔）
RUN mkdir -p storage/app/public storage/app/private storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && npm run build \
    && rm -rf node_modules

FROM base
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-kancil.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/kancil-entrypoint
COPY --from=build /app /app

# 以 www-data 執行；資料（SQLite 與上傳的媒體）都在 /app/storage，部署時掛成 volume
# （基礎映像檔已經讓 frankenphp 不必是 root 也能使用 80、443 port）
RUN mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /data /config /app/storage /app/bootstrap/cache \
    && php artisan storage:link
USER www-data

ENV DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database/kancil.sqlite \
    LOG_CHANNEL=stderr

EXPOSE 80 443 443/udp
ENTRYPOINT ["kancil-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
