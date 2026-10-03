# Kancil Quiz

給新住民語文老師的互動練習平台：同一份題組可以切換成多種遊戲。完整規格在 `docs/SPEC.md`，動手前先讀相關章節。規格與程式不一致時先提出來，不要自行選邊。

## 架構

- 後端與老師端：Laravel 13、Inertia 3、Vue 3、TypeScript，由官方 Vue starter kit 起始。前端工具鏈是 Vite+（`vp`）。
- `packages/` 是 npm workspaces，scope 為 `@kancil-quiz/*`：

| 套件 | 內容 | 規格 |
|---|---|---|
| `schema` | 交換格式的 JSON Schema（唯一規格來源）、自動產生的 TS 型別、共用 fixture | 第 6 節 |
| `text` | 正規化、答案比對、切字 | 第 8 節 |
| `deck` | 題組轉 `Round[]`、干擾選項、相容檢查、成績判定 | 7.3、7.4 |
| `games-sdk` | 遊戲模組介面 | 7.2 |
| `player` | 遊戲宿主，也可建置成獨立播放器 | 7.2、10.2 |
| `games/*` | 各個遊戲 | 7.5 |

- 學生端播放頁不走 Inertia：`/p/{activity}` 由 `resources/views/player.blade.php` 載入獨立的 Vite 入口 `resources/js/player.ts`。
- 後端的主要程式：
  - `app/Corpus/`：題組內容的寫入（`SetWriter`）、組成交換格式（`SetContent`）、產生版本（`SetRevisionRecorder`）、媒體網址改寫、活動播放格式（`ActivityPlayback`）。
  - `app/Grading/Judge.php`：伺服器端判分，與 `@kancil-quiz/deck` 的 `judge()` 是同一套規則。
  - `app/Grading/ActivityResults.php`：老師成績頁的逐題答錯率與作答明細（T-11），每題以第一筆作答計算，題目依作答當時的版本顯示。
  - `app/Games/GameRegistry.php`：讀 `packages/games/manifest.json`（由各遊戲的 `src/meta.ts` 產生）。
  - `app/Media/MediaProcessor.php`：ffmpeg 轉音檔、intervention/image 轉 WebP。
  - `app/Support/KancilFormat.php`：用 `opis/json-schema` 驗證題組與活動格式。
- 每個遊戲套件有 `src/meta.ts`（只有設定資訊，不含執行程式）與 `src/index.ts`（`{ ...meta, mount }`）。新增遊戲後要在 `resources/js/player.ts` 登記，並執行 `npm run games:manifest`。
- Model 的主鍵用 `App\Models\Concerns\HasUlids`：大寫、隨機部分不遞增（活動連結本身就是存取憑證）。

## 必守規則

1. **Schema 先行**：交換格式的變更先改 `packages/schema/*.schema.json`，執行 `npm run schema:types`，更新 fixture，最後才改程式。`packages/schema/src/generated.ts` 是自動產生的，不可手改。
2. **三份契約各有用途**（第 6 節開頭）：題組交換格式 `set.v1`、活動播放格式 `activity.v1`，以及遊戲使用的 `Round`。`Round` 只存在於瀏覽器記憶體中，不在網路上傳輸。
3. **文字處理集中**：所有文字以 NFC 儲存與比對。正規化、比對、切字只能呼叫 `@kancil-quiz/text`；把文字拆成格子或字塊時一律用 `graphemes()`，禁止用 `split('')`、code unit 或 code point 切字。
4. **遊戲不碰後端**：遊戲套件不得 import Laravel 或 Vue 相關程式，也不得發出網路請求。資料由宿主透過 context 提供，結果以事件回報。
5. **遊戲不判定正式成績**：遊戲回報學生選了什麼（`selected`），伺服器依作答時的題組版本重新判定（7.4）。判定規則在 Laravel 與 `@kancil-quiz/deck` 各有一份，兩邊共用同一批 fixture 測試。
6. **不可變的資料**：題組版本與媒體檔一經產生就不再修改，作答紀錄指向版本（3.3、第 9 節）。
7. **公開識別碼一律用 ULID**，網址與匯出檔不暴露遞增 ID。
8. **學生是未成年人**：不存 IP、不放第三方追蹤碼，只存暱稱或座號。
9. **語料全部自製**：不得放入國教署教材的文字、圖片或音檔（D-4）。

## Fixture

`packages/schema/fixtures/` 由 TS（`packages/schema/tests/`）與 PHP（`tests/Unit/KancilFormatTest.php`）共用：

- `sets/`：合法的題組。第一階段的 `id`、`vi` 各有一份詞彙組與問答組；`th-vocab-fruits` 是非必過的泰文預警 fixture。
- `activities/`：合法的活動。
- `invalid/set/`、`invalid/activity/`：不符合 schema，每個檔案只違反一條規則，檔名就是違反的內容。
- `invalid/rules/`：符合 schema，但違反 NFC 或 ID 不重複的規則。

`docs/SPEC.md` 第 6 節的 JSON 範例也會被測試拿去驗證，修改範例時要維持合法。

## 常用指令

| 指令 | 用途 |
|---|---|
| `composer setup` | 初次安裝：依賴、`.env`、migrate、前端建置 |
| `composer dev` | 啟動開發環境 |
| `npm test` | TS 測試（Vitest，經由 `vp test`） |
| `php artisan test` | PHP 測試（PHPUnit） |
| `npm run check` | 格式與 lint；`npm run check:fix` 自動修正 |
| `npm run types:check` | TS 型別檢查（`vue-tsc`） |
| `npm run schema:types` | 從 JSON Schema 重新產生 TS 型別 |
| `npm run schema:check` | 檢查產生的型別是否與 schema 同步 |
| `composer test` | Pint、PHPStan、PHPUnit |
| `npm run games:manifest` | 遊戲的 `meta.ts` 改變後，重新產生 `packages/games/manifest.json` |
| `composer ci:check` | CI 的完整檢查 |
| `php artisan db:seed --class=DemoSeeder` | 本機示範資料：teacher@example.com、admin@example.com（密碼都是 password），以及印尼語、越南語的題組與活動 |
| `php artisan kancil:invite --role=admin` | 建立註冊邀請連結（註冊一律需要邀請） |
| `npm run build && npx playwright test` | 端對端測試（獨立的 `database/e2e.sqlite`，iPad 直向、橫向與投影尺寸） |
| `node tests/Load/student-load.mjs --activity <ID>` | 學生端 API 壓力測試，用法見檔案開頭的說明 |

## 環境

- PHP 8.4 以上，需要 `intl`、`pdo_sqlite`、`gd`（含 WebP）擴充，以及 `ffmpeg`；Node 22 以上。
- 上傳的媒體放在 `public` disk，需要 `php artisan storage:link`（`composer setup` 會執行）。
- 執行 Playwright 的 WebKit（iPad Safari）需要系統套件：`sudo npx playwright install-deps`，之後設定 `E2E_WEBKIT=1`。
- `docs/` 與 `CLAUDE.md` 排除在 `vp fmt` 之外，因為它會把 Markdown 表格補滿空白、撐得很寬。
