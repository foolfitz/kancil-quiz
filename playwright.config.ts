import { defineConfig, devices } from '@playwright/test';

// 端對端測試（docs/SPEC.md 7.6、10.1）。使用獨立的 SQLite 檔與示範資料（DemoSeeder），
// 執行前要先 npm run build。
//
// iPad 的 Safari 要用 WebKit；Linux 上需要先安裝系統套件（sudo npx playwright install-deps），
// 安裝後設定 E2E_WEBKIT=1 就會一併執行。
const port = 8124;
const database = `${process.cwd()}/database/e2e.sqlite`;

const ipad = devices['iPad (gen 7)'];
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
                    viewport: { width: 1920, height: 1080 },
                },
            },
        ]),
    ],
    webServer: {
        command: `touch ${database} && php artisan migrate:fresh --seed --seeder=DemoSeeder --force && php artisan serve --no-reload --port=${port}`,
        url: `http://127.0.0.1:${port}/up`,
        reuseExistingServer: false,
        timeout: 120_000,
        env: {
            DB_DATABASE: database,
            APP_URL: `http://127.0.0.1:${port}`,
            PHP_CLI_SERVER_WORKERS: '4',
        },
    },
});
