import { defineConfig, devices } from '@playwright/test';

// 端對端測試（docs/SPEC.md 7.6、10.1）。使用獨立的 SQLite 檔與示範資料（DemoSeeder），
// 執行前要先 npm run build。
//
// iPad 的 Safari 要用 WebKit；Linux 上需要先安裝系統套件：npx playwright install-deps webkit
// （不要加 sudo，它會自己切換成 root）。安裝後設定 E2E_WEBKIT=1 就會一併執行。
const port = 8124;
const database = `${process.cwd()}/database/e2e.sqlite`;
// 示範資料會匯入教材插圖；媒體另外放，每次執行前清空，不留在開發用的 storage
const media = `${process.cwd()}/public/e2e-media`;

const ipad = devices['iPad (gen 7)'];
// 錄音（recording.spec.ts）：Chromium 用假的麥克風並自動允許；WebKit 沒有這個功能
const fakeMicrophone = {
    launchOptions: {
        args: [
            '--use-fake-device-for-media-stream',
            '--use-fake-ui-for-media-stream',
        ],
    },
};
const browsers = process.env.E2E_WEBKIT
    ? (['chromium', 'webkit'] as const)
    : (['chromium'] as const);

export default defineConfig({
    testDir: 'tests/e2e',
    outputDir: 'test-results/e2e',
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        locale: 'zh-TW',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        ...browsers.flatMap((browserName) => [
            {
                name: `${browserName}-ipad-portrait`,
                dependencies: ['setup'],
                use: {
                    storageState: 'test-results/.auth/teacher.json',
                    browserName,
                    ...(browserName === 'chromium' ? fakeMicrophone : {}),
                    viewport: ipad.viewport,
                    hasTouch: true,
                    isMobile: browserName !== 'chromium' ? undefined : true,
                    deviceScaleFactor: 2,
                },
            },
            {
                name: `${browserName}-ipad-landscape`,
                dependencies: ['setup'],
                use: {
                    storageState: 'test-results/.auth/teacher.json',
                    browserName,
                    ...(browserName === 'chromium' ? fakeMicrophone : {}),
                    viewport: {
                        width: ipad.viewport.height,
                        height: ipad.viewport.width,
                    },
                    hasTouch: true,
                    deviceScaleFactor: 2,
                },
            },
            {
                name: `${browserName}-projector`,
                dependencies: ['setup'],
                use: {
                    storageState: 'test-results/.auth/teacher.json',
                    browserName,
                    ...(browserName === 'chromium' ? fakeMicrophone : {}),
                    viewport: { width: 1920, height: 1080 },
                },
            },
        ]),
    ],
    webServer: {
        command: `rm -rf ${media} && touch ${database} && php artisan migrate:fresh --seed --seeder=DemoSeeder --force && php artisan serve --no-reload --port=${port}`,
        url: `http://127.0.0.1:${port}/up`,
        reuseExistingServer: false,
        timeout: 120_000,
        env: {
            DB_DATABASE: database,
            APP_URL: `http://127.0.0.1:${port}`,
            PUBLIC_DISK_ROOT: media,
            PUBLIC_DISK_URL: `http://127.0.0.1:${port}/e2e-media`,
            PHP_CLI_SERVER_WORKERS: '4',
            // 不存在的檔案：即使本機開著 composer dev（public/hot），也用建置好的前端
            VITE_HOT_FILE: `${process.cwd()}/storage/framework/e2e-no-hot`,
        },
    },
});
