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
| `games/*` | 各個遊戲；`maze-quiz` 是 git submodule（見下方） | 7.5 |

- 學生端播放頁不走 Inertia：`/p/{activity}` 由 `resources/views/player.blade.php` 載入獨立的 Vite 入口 `resources/js/player.ts`。老師的建立前預覽與訪客的教材試玩（`/curriculum/{language}/{volume}/{lesson}/play/{game}`，SPEC S-06）也用這一頁，直接帶入播放格式；試玩是 `startPlayer({ trial: true })`，不建立活動也不呼叫作答 API，只把開始與玩完送到計次的 API（`playsUrl`，`packages/player/src/plays.ts`，同一個分頁只算第一次）。
- 不需登入的公開頁面（首頁、`/curriculum` 與一課，SPEC S-06）：
  - 訪客用 `resources/js/layouts/GuestLayout.vue`，已登入的老師看教材時照舊用側邊欄，在 `resources/js/app.ts` 的 `layout` 依登入與否選擇。首頁不論登入與否都用 `GuestLayout`。
  - 給搜尋引擎與連結預覽：controller 以 `App\Support\PageMeta::make()` 產生 `meta` prop，`resources/views/partials/page-meta.blade.php` 寫進伺服器輸出的 `<head>`，Vue 端由 `components/kancil/PageMeta.vue` 以相同的 `head-key` 接手。
  - controller 用 `->withViewData(['skeleton' => 'skeletons.xxx'])` 指定骨架，`resources/views/app.blade.php` 把它放進 `#app`（沒有骨架的頁面照舊 `<x-inertia::app />`）。骨架只有結構、沒有樣式，有 JS 時以 `.js .kq-skeleton` 藏起來，Vue 掛上時清空 `#app`。不用 Inertia SSR，正式環境不需要 Node。本機開著 Vite 開發伺服器（`composer dev`）時 Inertia 會自動 SSR，這時照常用 SSR 的結果、不放骨架；PHPUnit 以 `INERTIA_SSR_ENABLED=false`（`phpunit.xml`）關掉，測試結果才不會因為有沒有開 `composer dev` 而不同。
  - 公開頁面不能透露老師的資料：訪客拿到的 prop 要另外檢查（`CurriculumTest` 的訪客測試）。
- 獨立播放器（O-02）在 `packages/player/standalone/`，有自己的 `vite.config.ts`，建置成單一個 HTML 檔 `public/standalone.html`（不進 git）。它打開匯出的 zip（`packages/player/src/zip.ts`、`setZip.ts`），以 `startPlayer({ standalone: true })` 播放，不連伺服器。新增遊戲時也要在 `standalone/main.ts` 登記。
- 後端的主要程式：
  - `app/Corpus/`：題組內容的寫入（`SetWriter`）、組成交換格式（`SetContent`）、產生版本（`SetRevisionRecorder`）、媒體網址改寫、活動播放格式（`ActivityPlayback`）、複製題組（`SetCopier`）、版本差異（`RevisionDiff`）、老師端畫面上的題目（`EntryFaces`）、匯出 zip 與 `LICENSE.txt`（`SetExport`）。
  - `app/Curriculum/`：教材（SPEC 3.6）。`CurriculumImporter` 匯入一冊的課名與詞彙、每一課產生教材題組，`preview()` 在交易中匯入後還原；`VolumeFile` 讀取資料檔（repo 的 `volume.json`，或後台上傳的「課文與詞彙.json」）；`CurriculumImages` 依檔名把插圖對應到一冊的詞；`Textbook` 是教材帳號與教材題組的共用設定；`TextbookData` 是老師端頁面的教材資料。repo 的 `database/curriculum/` 只放印尼語第 1 冊（示範資料與 E2E），格式見該目錄的 README；其他冊在後台匯入。
  - 人氣統計（SPEC A-04）：`App\Curriculum\PlayCounts` 把試玩的計次累加到 `curriculum_plays`（一課、一個遊戲、台灣時間的一天一列，只有次數），並和課堂的作答數（直接用教材題組建立的活動）一起彙總；後台頁面是 `app/Filament/Pages/PlayStats.php`，管理員與審核者都能看。
  - `app/Filament/Pages/ImportCurriculum.php`：後台的「匯入教材」頁，只有管理員能用：上傳詞彙的 JSON、批次上傳插圖，都先預覽再寫入。審核者修正過的課以 `CurriculumImporter::editedOnSite()` 判斷（最近一次匯入詞彙之後有沒有教材帳號以外的版本，`curriculum_refs.imported_revision`）；寫入教材題組的程式要以教材帳號產生版本，否則會被當成修正。
  - 帳號（SPEC T-01、T-03、第 5 節、A-05）：老師用 Google 登入（Socialite，`App\Http\Controllers\Auth\GoogleLoginController`），帳號的對應與建立在 `App\Auth\GoogleAccounts`：同一個 Google 帳號 → 同一個 email 且還沒連結 Google 的帳號 → 建立新的老師。Fortify 只留密碼登入、雙重驗證與 passkey，給有密碼的帳號（管理員、示範帳號），沒有註冊、重設密碼與驗證信；Filament 沒有自己的登入頁，沒登入時導到 `/login`。設定頁的「安全性」只給有密碼的帳號（`EnsureUserHasPassword`）。刪除帳號是匿名化（`App\Auth\AccountDeletion`），不真的刪除使用者；管理員停用的帳號（`users.disabled_at`）由 `EnsureAccountIsActive` 登出，他的活動、分享連結與開放資料都回 404，公開題組不列在共備庫（`Set::listed()`、`Set::isListed()`、`Set::sharedBy()`）。新增列出公開題組或播放活動的地方時，要一併排除停用的擁有者。
  - 播放頁的檢舉（SPEC S-07）：`POST /api/v1/activities/{activity}/reports` 存進 `reports`，寄信給管理員（`ActivityReported`，寄不出去不影響），後台的「檢舉」（`app/Filament/Resources/Reports`）處理。
  - 工作階段存在資料庫，但不記錄 IP 與瀏覽器（`App\Support\SessionHandler` 取代 `database` driver）。
  - 老師端側邊欄的「後台」連結只給能進 Filament 的人（`auth.adminUrl`）；`NavItem.external` 的項目用一般連結整頁載入，不走 Inertia。
  - `app/Policies/SetPolicy.php`：題組權限。`manage`（擁有者）、`edit`（加上審核者修正公開題組）、`view`、`copy`、`export`（manage 或已公開）、`review`、`createActivity`（manage，加上所有老師都能用教材題組）；教材題組沒有人能 `manage` 或 `review`。未公開題組的分享連結以 token 判斷，不經過 policy。
  - `app/Grading/Judge.php`：伺服器端判分，與 `@kancil-quiz/deck` 的 `judge()` 是同一套規則。
  - `app/Grading/ActivityResults.php`：老師成績頁的逐題答錯率與作答明細（T-11），每題以第一筆作答計算，題目依作答當時的版本顯示。學生有填名字時依名字彙整（`students()`），每位學生只算第一次玩完的作答，重玩的作答不列入平均與逐題統計（SPEC 3.4、7.4）；CSV 由 `ActivityResultsCsvController` 產生。
  - `app/Support/ActivitySettings.php`：活動的兩個設定（SPEC 3.4）：要不要輸入名字（存成 `mode = assignment`）與開放、截止時間。老師端以 `config('kancil.timezone')`（台灣時間）輸入與顯示，格式同 `datetime-local`，資料庫存 UTC；建立活動的預設是今天起一週。
  - `app/Grading/PlayerLabel.php`：學生輸入的名字或座號在存入前統一格式（NFKC、合併空白、數字去掉前導的 0）。
  - `app/Games/GameRegistry.php`：讀 `packages/games/manifest.json`（由各遊戲的 `src/meta.ts` 產生）。
  - `app/Media/MediaProcessor.php`：ffmpeg 轉音檔、intervention/image 轉 WebP。瀏覽器錄音（T-06）也原樣上傳到這裡；前端的錄音在 `resources/js/lib/recorder.ts`、`components/kancil/AudioRecorder.vue`（單一欄位）與 `SequentialRecorder.vue`（逐詞錄音）。E2E 的 Chromium 用假的麥克風（`playwright.config.ts`），WebKit 不測錄音。
  - `app/Support/KancilFormat.php`：用 `opis/json-schema` 驗證題組與活動格式。
  - `app/Support/Pruner.php`：資料的保存期限（SPEC 第 5 節），由排程每天執行 `kancil:prune`（`routes/console.php`，正式環境是 `compose.yaml` 的 `scheduler` 服務）。期限在 `config/kancil.php` 的 `retention`。新增會引用媒體或題組版本的資料時，要把它加進 `Pruner` 的引用檢查，否則被引用的媒體或版本會被清掉。
- 每個遊戲套件有 `src/meta.ts`（只有設定資訊，不含執行程式）與 `src/index.ts`（`{ ...meta, mount }`）。新增遊戲後要在 `resources/js/player.ts` 與 `packages/player/standalone/main.ts` 登記，並執行 `npm run games:manifest`。
- 遊戲分成計分的「遊戲」與不計分的「互動教材」（字卡、圖卡牆、轉盤），由 `requires.scored` 決定；老師端與獨立播放器用 `@kancil-quiz/deck` 的 `groupGames()` 分組（SPEC 7.5）。
- 會翻面的卡片不要只靠 `backface-visibility`：Playwright 的 WebKit（Linux）不支援，背面會鏡像蓋在正面上。三個卡片類的遊戲都在翻到一半時用 `visibility` 藏起背面，E2E 以 `toBeHidden()` 檢查；翻面的那一層也不要加 `container-type` 或 `overflow`，縮放用的 container 放在裡面一層。
- 遊戲把不碰 DOM 的進行狀態寫成 `src/session.ts`，用 Vitest 測試；畫面與觸控由 E2E 測試（`tests/e2e/`，共用的檢查在 `helpers.ts`）。計分的遊戲要有一個測試，確認遊戲的 `correct` 與 `@kancil-quiz/deck` 的 `judge()` 在所有 fixture 上一致（SPEC 7.6）。
- `answered` 事件的 `presented`：`mcq` 由宿主補上；配對的右側卡片分頁出現，由遊戲提供同一頁的卡片（SPEC 7.2）。伺服器限制每筆最多 12 個。
- 迷宮問答 `packages/games/maze-quiz` 是 git submodule，正本是獨立的 `maze-quiz` repo（MIT，SPEC 10.2），本機放在本 repo 旁邊的 `../maze-quiz`：
  - 改迷宮時固定在 `../maze-quiz` 修改、測試（`npm test`、`npm run check`）、commit，再回到這裡執行 `git -c protocol.file.allow=always submodule update --remote packages/games/maze-quiz`，跑完這裡的檢查後 commit 新的 submodule 指標。不要直接在 submodule 目錄裡改，兩個工作目錄容易搞混；忘了更新指標，平台會停在舊版的迷宮。
  - 它單獨執行時用 `vendor/` 中 `games-sdk` 與 `text` 的副本。改了這兩個套件，要把新版複製到 `../maze-quiz/vendor/` 並 commit，再更新指標；`packages/games/maze-quiz.test.ts` 會檢查副本與正本相同。
  - 迷宮與 `judge()` 的一致測試（SPEC 7.6）也在 `packages/games/maze-quiz.test.ts`，因為要用到 `deck` 與 fixture。它直接 import 迷宮的內部模組（`src/session.ts` 等），迷宮重構時要一起改。
  - 這裡的 `vp check`、`vue-tsc` 與 Vitest 也會涵蓋 submodule 的檔案，所以迷宮的格式設定與這裡相同。
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
9. **教材只引用課名與詞彙**：冊課對照依據國教署[新住民子女教育資訊網](https://mkm.k12ea.gov.tw/textbook)的「新住民語文學習教材」（紙本採 CC BY-NC-ND 4.0）。可以放入各課的課名與詞彙（詞與中文意思）；不得放入課文、教材插圖與音檔，插圖與發音一律自製（D-4）。

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
| `composer setup` | 初次安裝：submodule、依賴、`.env`、migrate、前端建置 |
| `composer dev` | 啟動開發環境 |
| `npm test` | TS 測試（Vitest，經由 `vp test`） |
| `php artisan test` | PHP 測試（PHPUnit） |
| `npm run check` | 格式與 lint；`npm run check:fix` 自動修正 |
| `npm run types:check` | TS 型別檢查（`vue-tsc`） |
| `npm run schema:types` | 從 JSON Schema 重新產生 TS 型別 |
| `npm run schema:check` | 檢查產生的型別是否與 schema 同步 |
| `composer test` | Pint、PHPStan、PHPUnit |
| `git -c protocol.file.allow=always submodule update --remote packages/games/maze-quiz` | 把迷宮更新到 `../maze-quiz` 的最新 commit，之後要 commit 新的指標 |
| `npm run games:manifest` | 遊戲的 `meta.ts` 改變後，重新產生 `packages/games/manifest.json` |
| `composer ci:check` | CI 的完整檢查 |
| `php artisan db:seed --class=DemoSeeder` | 本機示範資料：匯入印尼語第 1 冊的教材題組；teacher@example.com（示範老師）、colleague@example.com（示範同事，共備庫的公開題組）、curator@example.com（審核者）、admin@example.com，密碼都是 password（在登入頁下方的「管理員：用 email 與密碼登入」）。本機沒有設定 `GOOGLE_CLIENT_ID` 時不顯示 Google 登入 |
| `php artisan kancil:import-curriculum database/curriculum/id/1` | 從 repo 匯入一冊教材的課名與詞彙；`--force` 覆寫審核者在網站上的修正，`--refresh-images` 重新匯入插圖。其他冊在後台 `/admin/import-curriculum` 匯入 |
| `php artisan kancil:create-admin <email>` | 建立管理員（會要求設定密碼），或把既有的帳號設為管理員。老師不需要邀請，用 Google 登入就會建立帳號 |
| `php artisan kancil:prune --dry-run` | 列出排程會清除的資料筆數，不刪除；拿掉 `--dry-run` 就會真的刪除 |
| `npm run build:standalone` | 只建置獨立播放器（`npm run build` 會一併執行） |
| `npm run build && npx playwright test` | 端對端測試（獨立的 `database/e2e.sqlite`，媒體放在 `public/e2e-media`；iPad 直向、橫向與投影尺寸） |
| `node tests/Load/student-load.mjs --activity <ID>` | 學生端 API 壓力測試，用法見檔案開頭的說明 |
| `docker compose up -d --build` | 正式環境（`docs/deploy.md`）；需要 `.env.production`，範本是 `.env.production.example` |

## 環境

- PHP 8.4 以上，需要 `intl`、`pdo_sqlite`、`gd`（含 WebP）、`zip` 擴充，以及 `ffmpeg`；Node 22 以上。
- 上傳的媒體放在 `public` disk，需要 `php artisan storage:link`（`composer setup` 會執行）。
- 執行 Playwright 的 WebKit（iPad Safari）需要系統套件：`npx playwright install-deps webkit`，之後設定 `E2E_WEBKIT=1`。不要在前面加 `sudo`：`npx` 不在 sudo 的 PATH 中；Playwright 會自己用 sudo 切換成 root 執行 apt，會要求輸入密碼。
- submodule 的網址是本機路徑時（本機的這份 repo，或從本機路徑 clone 的），git 2.38 起 submodule 的 clone 與 fetch 都要加 `-c protocol.file.allow=always`，例如上面的 `submodule update --remote`、`submodule update --init`；從 GitHub clone 的不需要。
- `docs/` 與 `CLAUDE.md` 排除在 `vp fmt` 之外，因為它會把 Markdown 表格補滿空白、撐得很寬。
- 正式環境的映像檔（`Dockerfile`）：FrankenPHP 加上 PHP 擴充與 ffmpeg，PHP 設定在 `docker/php.ini`，Caddy 在 `docker/Caddyfile`。新增 PHP 擴充或系統套件時兩邊（本機與 `Dockerfile`）都要裝。`.dockerignore` 排除整個 `storage/`（本機的資料庫備份與快取不能進映像檔），映像檔中的空目錄由 `Dockerfile` 建立。
