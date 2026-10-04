import { expect, test } from '@playwright/test';
import { collectErrors, matchPage } from './helpers';

// 獨立播放器（docs/SPEC.md O-02、M2 驗收 3）：匯出的 zip 不經過伺服器，直接在靜態頁面中遊玩。
// 頁面是 npm run build 產生的 public/standalone.html。

test('老師匯出私人題組，在獨立播放器中玩配對與字卡', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);

    // 已由 auth.setup.ts 以老師身分登入
    await page.goto('/sets');
    await page.getByRole('link', { name: /水果（越南語）/ }).click();
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.locator('[data-test="export-set"]').click(),
    ]);
    const zip = testInfo.outputPath('fruits.zip');
    await download.saveAs(zip);

    // 播放時完全不需要伺服器的作答 API
    await page.context().clearCookies();
    const apiCalls: string[] = [];
    page.on('request', (request) => {
        if (request.url().includes('/api/')) {
            apiCalls.push(request.url());
        }
    });

    await page.goto('/standalone.html');
    await page.locator('.kq-standalone__file').setInputFiles(zip);
    await expect(
        page.getByRole('heading', { name: '水果（越南語）' }),
    ).toBeVisible();
    await expect(page.locator('.kq-standalone__game:enabled')).toHaveCount(4);
    await page.screenshot({ path: testInfo.outputPath('games.png') });

    await page.getByRole('button', { name: /配對/ }).click();
    await page.getByRole('button', { name: '開始' }).click();
    await matchPage(page);
    await expect(page.locator('.kq-match__progress')).toHaveText(
        '第 2 / 2 頁｜配好 0 / 4 組',
    );
    await matchPage(page);
    await expect(
        page.getByRole('heading', { name: '答對 8 / 8 題' }),
    ).toBeVisible();
    // 本來就不上傳，不必提示「成績沒有上傳成功」
    await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);

    // 換一個遊戲
    await page.getByRole('button', { name: '← 換遊戲' }).click();
    await page.getByRole('button', { name: /字卡/ }).click();
    await page.getByRole('button', { name: '開始' }).click();
    await expect(page.locator('.kq-cards__progress')).toHaveText('第 1 / 8 張');

    expect(apiCalls).toEqual([]);
    expect(errors).toEqual([]);
});

test('從教材的一課用獨立播放器開啟（公開題組的網址）', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);

    await page.goto('/curriculum/id/1/3');
    const [standalone] = await Promise.all([
        page.waitForEvent('popup'),
        page.locator('[data-test="standalone-set"]').click(),
    ]);
    const standaloneErrors = collectErrors(standalone);
    await expect(
        standalone.getByRole('heading', {
            name: '第 1 冊第 3 課：Keluarga Saya 我的家人',
        }),
    ).toBeVisible();
    // 授權與出處來自 zip 中的 LICENSE.txt
    await standalone.getByText('授權與出處').click();
    await expect(
        standalone.locator('.kq-standalone__license pre'),
    ).toContainText('出處 AI 生成');

    await standalone.getByRole('button', { name: /選擇題/ }).click();
    await standalone.getByRole('button', { name: '開始' }).click();
    await expect(standalone.locator('.kq-quiz__progress')).toHaveText(
        '第 1 / 6 題',
    );
    // 插圖從 zip 中載入
    await expect
        .poll(() =>
            standalone
                .locator('.kq-quiz__prompt img')
                .evaluate((img: HTMLImageElement) =>
                    img.src.startsWith('blob:') ? img.naturalWidth : 0,
                ),
        )
        .toBeGreaterThan(0);
    await standalone.screenshot({
        path: testInfo.outputPath('standalone-quiz.png'),
    });

    expect(errors).toEqual([]);
    expect(standaloneErrors).toEqual([]);
});

test('不是題組的檔案會說明原因', async ({ page }) => {
    await page.goto('/standalone.html');
    await page.locator('.kq-standalone__file').setInputFiles({
        name: 'notes.zip',
        mimeType: 'application/zip',
        buffer: Buffer.from('hello'),
    });
    await expect(page.getByRole('alert')).toHaveText('不是 zip 檔');
});
