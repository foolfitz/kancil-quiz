import { expect, test } from '@playwright/test';

// 共備（docs/SPEC.md M2）：老師在共備庫找到別人的題組、複製後改編，並產生分享連結給同事。
// 資料來自 DemoSeeder：林老師有兩個公開的題組。複製印尼語的問答組，
// 避免與迷宮、選擇題測試在「我的題組」中點選的越南語題組同名。
test('老師從共備庫複製題組，並產生分享連結', async ({ page }, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/library');
    await expect(page.locator('[data-test="library-set"]')).toHaveCount(2);

    // 依語言篩選（T-13）
    await page.getByLabel('語言').selectOption({ label: '印尼語' });
    await expect(page.locator('[data-test="library-set"]')).toHaveCount(1);
    await expect(page.getByLabel('教材冊課')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('library.png') });

    await page.locator('[data-test="library-set"]').click();
    await expect(
        page.getByRole('heading', { name: '打招呼（印尼語）' }),
    ).toBeVisible();
    await expect(page.getByText('林老師').first()).toBeVisible();
    await expect(
        page.locator('[data-test="set-entries"] > li'),
    ).not.toHaveCount(0);

    await page.screenshot({
        path: testInfo.outputPath('set-view.png'),
        fullPage: true,
    });

    // 複製到自己的題組後進入編輯頁
    await page.locator('[data-test="copy-set"]').click();
    await page.waitForURL('**/sets/*/edit');
    await expect(page.getByLabel('標題')).toHaveValue('打招呼（印尼語）');
    await expect(page.getByText('已複製到我的題組')).toBeVisible();

    // T-17：產生分享連結
    await page.getByRole('button', { name: '產生分享連結' }).click();
    await expect(page.locator('[data-test="share-url"]')).toContainText(
        '/shared/',
    );
    await expect(page.getByRole('button', { name: '申請公開' })).toBeVisible();
    await page.locator('[data-test="sharing-panel"]').screenshot({
        path: testInfo.outputPath('sharing-panel.png'),
    });

    expect(errors).toEqual([]);
});
