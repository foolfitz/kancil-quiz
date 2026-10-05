# 部署

Kancil Quiz 以 Docker Compose 部署在一台雲端 VM 上（規格第 11 節）。

## 架構

- 網站的容器 `app`：[FrankenPHP](https://frankenphp.dev/) 同時負責 PHP 與靜態檔案，內建的 Caddy 會自動向 Let's Encrypt 取得並更新 HTTPS 憑證。錄音（T-06）只能在 HTTPS 下使用。
- 排程的容器 `scheduler`：同一個映像檔，執行 `php artisan schedule:work`。每天台灣時間凌晨 4 點（在備份之後）執行 `kancil:prune`，清除過了保存期限的資料（見下方「資料的保存期限」）。
- 映像檔由 repo 中的 `Dockerfile` 建置：PHP 8.4 加上 `intl`、`gd`（WebP）、`zip` 擴充、ffmpeg 與 sqlite3，前端在建置時打包好。上傳上限等 PHP 設定在 `docker/php.ini`，Caddy 的設定在 `docker/Caddyfile`。
- 資料都在 volume `storage`（容器中的 `/app/storage`）：SQLite 資料庫 `database/kancil.sqlite` 與上傳的媒體 `app/public/`。備份這一個 volume 就夠了。
- 容器啟動時會快取設定並套用 migration（`docker/entrypoint.sh`）。
- 目前沒有佇列任務，不需要 queue worker。

## 需要準備的

| 項目 | 說明 |
|---|---|
| VM | Ubuntu 24.04 LTS；1 vCPU、2 GB 記憶體、20 GB 硬碟就夠。映像檔在 VM 上建置，打包前端時比較吃記憶體，1 GB 的機器建議先加 swap |
| 網路 | 防火牆或雲端的安全群組開放 TCP 80、443，以及 UDP 443（HTTP/3，選用） |
| 網域 | 一個網域或子網域，DNS 的 A 記錄指向 VM 的 IP。Caddy 用它申請憑證 |
| Google 帳號 | 在 Google Cloud Console 建立 OAuth 用戶端，老師用 Google 登入（見下方「Google 登入」） |
| 寄信（選用） | SMTP 帳號，有人檢舉活動時寄信通知管理員。沒有的話信只會寫進記錄，檢舉照樣出現在後台 |

### 安裝 Docker（要 root，只做一次）

在 VM 上執行（Docker 官方的安裝腳本）：

```sh
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
```

登出再登入後，`docker` 就不必再加 `sudo`。之後的部署、更新、備份都不需要 root。

## 第一次部署

在家目錄執行：

```sh
cd ~
git clone --recurse-submodules https://github.com/foolfitz/kancil-quiz.git
cd kancil-quiz
cp .env.production.example .env.production
docker compose build
docker compose run --rm app php artisan key:generate --show
```

最後一行會印出 `base64:` 開頭的金鑰。編輯 `.env.production`：

- `APP_KEY`：貼上剛才的金鑰。
- `APP_URL`：`https://` 加上你的網域。
- `SERVER_NAME`：你的網域，不加 `https://`。
- `KANCIL_INDEXING`：用暫用網域時設成 `false`，所有頁面都不讓搜尋引擎收錄（見下方「換網域」）。用正式網域就不必設定。
- `GOOGLE_CLIENT_ID`、`GOOGLE_CLIENT_SECRET`：見下方「Google 登入」。
- `KANCIL_OPERATOR`、`KANCIL_CONTACT_EMAIL`：顯示在隱私權政策與使用條款（`/privacy`、`/terms`）上的營運者與聯絡信箱。上線前請讀過這兩頁，確認內容符合你的情況。
- 有 SMTP 帳號的話，填寫 `MAIL_` 開頭的設定。

啟動：

```sh
docker compose up -d
docker compose logs -f app
```

記錄中出現 `certificate obtained successfully` 就表示 HTTPS 憑證已經取得，按 Ctrl+C 離開記錄。接著匯入教材，並建立管理員（會要求輸入兩次密碼）：

```sh
docker compose exec app php artisan kancil:import-curriculum database/curriculum/id/1
docker compose exec -it app php artisan kancil:create-admin you@example.org
```

管理員在 `/login` 下方的「管理員：用 email 與密碼登入」登入，也可以用同一個 email 的 Google 帳號登入。老師不需要邀請，用 Google 登入就會建立帳號。審核者請對方先用 Google 登入一次，再由管理員在後台的「使用者」指定角色與負責的語言。

### Google 登入

1. 到 [Google Cloud Console](https://console.cloud.google.com/) 建立專案，在「API 和服務」→「OAuth 同意畫面」設定應用程式名稱、支援信箱，以及隱私權政策（`https://你的網域/privacy`）與服務條款（`https://你的網域/terms`）的網址。使用者類型選「外部」。只用 `openid`、`email`、`profile` 這三個基本範圍，通常不必經過 Google 的應用程式驗證（上傳應用程式標誌時則要）。
2. 在「憑證」→「建立憑證」→「OAuth 用戶端 ID」，類型選「網頁應用程式」，「已授權的重新導向 URI」填 `https://你的網域/auth/google/callback`。
3. 把用戶端 ID 與密鑰填進 `.env.production` 的 `GOOGLE_CLIENT_ID`、`GOOGLE_CLIENT_SECRET`，執行 `docker compose up -d`。
4. 發布狀態要改成「正式版」，否則只有測試使用者能登入。

repo 中只有印尼語第 1 冊。其他冊用管理員帳號在後台的「匯入教材」頁匯入：先上傳詞彙的 JSON，再依檔名批次上傳插圖（`docs/SPEC.md` 3.6）。

`.env.production` 中的 `APP_KEY` 要另外妥善保存：換掉的話，老師的雙重驗證設定會失效。

## 更新

```sh
cd ~/kancil-quiz
git pull
git submodule update --init
docker compose up -d --build
```

新的容器啟動時會自動套用 migration，網站會中斷幾秒。

要回到舊版時，`git checkout` 到舊的 commit 後同樣執行 `git submodule update --init` 與 `docker compose up -d --build`。migration 不會自動還原，回到舊版之前先備份。

## 常用指令

| 指令 | 用途 |
|---|---|
| `docker compose logs -f app` | 看記錄（PHP 的錯誤也在這裡） |
| `docker compose exec app php artisan <指令>` | 執行 artisan，例如 `kancil:prune --dry-run` |
| `docker compose exec -it app php artisan kancil:create-admin <email>` | 建立管理員，或把既有的帳號設為管理員 |
| `docker compose up -d` | 改了 `.env.production` 之後套用新設定（會重建兩個容器）。`docker compose restart` 不會讀入新的設定 |
| `docker compose logs scheduler` | 看排程的記錄 |
| `docker compose ps` | 查看容器狀態 |

## 備份與還原

`docker/backup.sh` 用 SQLite 的 `.backup` 取得一致的資料庫快照（網站不必停止），連同上傳的媒體打包成一個 `kancil-<時間>.tar.gz`，預設保留最近 14 份：

```sh
docker/backup.sh ~/backups
```

先手動執行一次，確認可以備份（也會建立 `~/backups`）。之後每天凌晨 3 點自動備份（`crontab -e` 加入這一行，不需要 root）：

```
0 3 * * * cd ~/kancil-quiz && docker/backup.sh ~/backups >> ~/backups/backup.log 2>&1
```

規格要求備份放到異地。VM 上的 `~/backups` 只是第一份，還要再複製到別的地方，例如用 `rclone` 同步到雲端儲存空間，或使用雲端供應商的磁碟快照。`.env.production` 也要一起保存。

還原會覆蓋目前的資料庫與媒體，網站會停止幾秒：

```sh
docker/restore.sh ~/backups/kancil-20261004-030000.tar.gz
```

規格要求每季實際演練還原一次。可以在另一台機器上用同一份備份與 `.env.production` 部署，確認資料完整。

## 資料的保存期限

`scheduler` 容器每天凌晨 4 點執行 `kancil:prune`（規格第 5 節）：

| 資料 | 清除的時機 |
|---|---|
| 作答紀錄 | 開始作答 12 個月後。要改的話在 `.env.production` 設定 `KANCIL_ATTEMPT_RETENTION_MONTHS`，再執行 `docker compose up -d` |
| 老師刪除的題組、活動與詞條 | 刪除 30 天後真正刪除，連同活動的作答紀錄。這 30 天內可以由管理員在資料庫中復原 |
| 舊的題組版本 | 被新版本取代 30 天後，沒有作答或審核紀錄引用的 |
| 媒體 | 沒有任何詞條、題目或版本用到，而且上傳超過 7 天的，連同檔案 |

每次的結果附加在 volume 中的 `storage/logs/prune.log`。想先看看會刪除多少，或手動執行一次：

```sh
docker compose exec scheduler php artisan kancil:prune --dry-run
docker compose exec scheduler php artisan kancil:prune
```

刪除的資料只能從備份還原，所以排程排在每天凌晨 3 點的備份之後。

## 疑難排解

| 狀況 | 檢查 |
|---|---|
| 瀏覽器顯示憑證錯誤，或連不上 | DNS 是否已經指向這台 VM（`dig +short 你的網域`）；80、443 port 是否開放；`docker compose logs app` 中搜尋 `acme` 看 Caddy 的錯誤 |
| 頁面顯示 500 錯誤 | `docker compose logs app`。不要在正式環境打開 `APP_DEBUG` |
| 容器一直重新啟動 | `docker compose logs app`；最常見的是 `.env.production` 沒有填 `APP_KEY` |
| 上傳失敗 | 單檔上限 5 MB；PHP 的上限設定在 `docker/php.ini`。每位老師的總量上限預設 200 MB（`KANCIL_UPLOAD_QUOTA_MB`），需要更多空間的老師在後台「使用者」的編輯頁個別調整 |
| 要換網域 | 見下方「換網域」 |

## 換網域

先用暫用網域上線時，在 `.env.production` 設定 `KANCIL_INDEXING=false`：Caddy 會在所有回應加上 `X-Robots-Tag: noindex`，搜尋引擎不會收錄這個網域，日後換網域時不會留下重複的內容。`robots.txt` 照常開放，搜尋引擎要讀得到頁面才看得到 `noindex`。分享的連結與 LINE、Facebook 的連結預覽不受影響。

換到正式網域時：

1. 新網域的 DNS 指向這台 VM。
2. 修改 `.env.production`：
   - `SERVER_NAME`、`APP_URL` 改成新網域。
   - 拿掉 `KANCIL_INDEXING=false`。
   - `MAIL_FROM_ADDRESS` 如果用到舊網域，也一起改。
3. 執行 `docker compose up -d`。
4. Google Cloud Console：
   - OAuth 用戶端的「已授權的重新導向 URI」加上 `https://新網域/auth/google/callback`。
   - 同意畫面的授權網域、隱私權政策與服務條款的網址改成新網域。
5. 已經發出去的活動連結與 QR code 都是舊網域。要讓它們繼續有效，`SERVER_NAME` 可以同時寫兩個網域（例如 `new.example.org, quiz.katasumi.asia`）。舊網域要改成轉址的話，在 `docker/Caddyfile` 另外加一段 `redir`。

## 尚未包含

- 監控與告警：可以先用外部的網站監測服務定時檢查 `https://你的網域/up`。
