import { expect, test } from '@playwright/test';

// 共備（docs/SPEC.md M2）：老師在共備庫找到別人的題組、複製後改編，並產生分享連結給同事。
// 資料來自 DemoSeeder：印尼語第 1 冊四課的教材題組，以及林老師由教材改編的兩個公開題組。
test('老師從共備庫複製題組，並產生分享連結', async ({ page }, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const sets = page.locator('[data-test="library-set"]');

    await page.goto('/library');
    await expect(sets).toHaveCount(6);

    // 依語言、冊、課篩選（T-13）
    await page.getByLabel('語言').selectOption({ label: '印尼語' });
    await page
        .getByRole('combobox', { name: '冊', exact: true })
        .selectOption({ label: '第 1 冊' });
    await page
        .getByRole('combobox', { name: '課', exact: true })
        .selectOption({ label: '第 3 課 Keluarga Saya 我的家人' });
    await expect(sets).toHaveCount(2);
    await expect(sets.getByText('教材', { exact: true })).toHaveCount(1);
    await page.screenshot({ path: testInfo.outputPath('library.png') });

    await sets.filter({ hasText: '看圖選詞' }).click();
    await expect(
        page.getByRole('heading', {
            name: '第 1 冊第 3 課 我的家人（看圖選詞）',
        }),
    ).toBeVisible();
    await expect(page.getByText('林老師').first()).toBeVisible();
    await expect(page.locator('[data-test="set-entries"] > li')).toHaveCount(6);

    await page.screenshot({
        path: testInfo.outputPath('set-view.png'),
        fullPage: true,
    });

    // 複製到自己的題組後進入編輯頁
    await page.locator('[data-test="copy-set"]').click();
    await page.waitForURL('**/sets/*/edit');
    await expect(page.getByLabel('標題')).toHaveValue(
        '第 1 冊第 3 課 我的家人（看圖選詞）',
    );
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
