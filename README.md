# Kancil Quiz

給新住民語文老師的互動練習平台：老師出一份題組，就能切換成迷宮問答、選擇題、字卡、配對等不同的遊戲，學生用平板掃 QR code 就能玩。目標是成為 Wordwall 的開源替代方案，語料可以共備、分享與完整匯出。

完整規格見 [`docs/SPEC.md`](docs/SPEC.md)。

## 狀態

開發中。M1（MVP）與 M2（共備）已完成，M3（課堂）進行中，進度見規格第 13 節。第一階段支援印尼語與越南語。

## 開發

需要 PHP 8.4 以上（`intl`、`pdo_sqlite`、`gd` 含 WebP、`zip` 擴充）、ffmpeg，以及 Node 22 以上。

```sh
git clone https://github.com/foolfitz/kancil-quiz.git
cd kancil-quiz
composer setup                            # submodule、依賴、.env、資料庫、前端建置
php artisan db:seed --class=DemoSeeder    # 示範資料（選用）
composer dev                              # 開發伺服器
```

示範帳號是 `teacher@example.com`，密碼 `password`。架構、常用指令與開發規則見 [`CLAUDE.md`](CLAUDE.md)。

每次 push 與 PR，GitHub Actions 會執行 `composer ci:check`（格式、型別、Vitest、PHPUnit）與 Chromium 的端對端測試（Playwright，`.github/workflows/tests.yml`）。

## 部署

以 Docker Compose 部署在一台 VM 上，自動取得 HTTPS 憑證。步驟、更新與備份見 [`docs/deploy.md`](docs/deploy.md)。

## 授權

- 程式碼：[AGPL-3.0-or-later](LICENSE)。
- 迷宮問答（`packages/games/maze-quiz`）是獨立的 [maze-quiz](https://github.com/foolfitz/maze-quiz) repo，採 MIT 授權，字型 Andika 採 SIL OFL。
- 內建的遊戲（選擇題、配對、打地鼠、射氣球）與互動教材（字卡、圖卡牆、轉盤）分別在 [kancil-games](https://github.com/foolfitz/kancil-games) 與 [kancil-materials](https://github.com/foolfitz/kancil-materials) 兩個 repo，以 git submodule 掛在 `packages/games/`，採 AGPL-3.0-or-later，只能在本平台中使用。
- 教材資料（`database/curriculum/`）：課名與詞彙依據國教署「新住民語文學習教材」，插圖自製。來源與授權見[該目錄的說明](database/curriculum/README.md)。
