import { expect, test } from '@playwright/test';

// 迷宮問答（docs/SPEC.md M1 驗收 3、4）：學生掃 QR code 開啟後能開始遊戲，畫面正常、沒有錯誤。
// 自動走完迷宮不實際，這裡只確認載入、繪製、暫停，並留下截圖供目視檢查越南文。

test('學生開啟迷宮問答並開始遊戲', async ({ page }, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });

    // 從老師端找到示範資料中「水果（越南語）」的迷宮問答活動
    // 已由 auth.setup.ts 以老師身分登入
    await page.goto('/sets');
    await page.getByRole('link', { name: /水果（越南語）/ }).click();
    await page.getByRole('link', { name: '迷宮問答' }).click();
    await page.waitForURL('**/activities/*');
    const url = await page.locator('code').first().textContent();
    await page.context().clearCookies(); // 學生不登入

    await page.goto(new URL(url ?? '').pathname);
    await page.getByRole('button', { name: '開始' }).click();

    const canvas = page.locator('.kq-maze canvas').first();
    await expect(canvas).toBeVisible();
    const box = await canvas.boundingBox();
    expect(box?.width).toBeGreaterThan(200);
    expect(box?.height).toBeGreaterThan(200);

    // 題目是中文意思，選項是越南文
    await expect(page.locator('.kq-maze')).toContainText(
        /香蕉|蘋果|柳橙|芒果|西瓜|葡萄|鳳梨|木瓜/,
    );

    await page.waitForTimeout(2500);
    await page.screenshot({ path: testInfo.outputPath('maze.png') });

    await page.keyboard.press('Escape');
    await expect(page.locator('.kq-maze')).toContainText('繼續');

    expect(errors).toEqual([]);
});
