import { expect, test } from '@playwright/test';

// 教材（docs/SPEC.md T-18）：老師依語言、冊、課找到一課，直接建立活動，或挑詞建立自己的題組。
// 資料來自 DemoSeeder：印尼語第 1 冊第 1 到 4 課的課名、詞彙與插圖。

test('老師用教材的一課直接建立活動，學生看圖選詞', async ({
    page,
}, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    // 已由 auth.setup.ts 以老師身分登入
    await page.goto('/dashboard');
    await page.getByRole('link', { name: '從教材開始' }).click();
    const lessons = page.locator('[data-test="curriculum-lesson"]');
    await expect(lessons).toHaveCount(4);
    await page.screenshot({ path: testInfo.outputPath('curriculum.png') });

    await lessons.filter({ hasText: 'Keluarga Saya' }).click();
    await page.waitForURL('**/curriculum/id/1/3');
    const words = page.locator('[data-test="lesson-words"] > li');
    await expect(words).toHaveCount(6);
    await expect(words.first()).toContainText('ayah');
    // 插圖由 DemoSeeder 匯入，確認真的載入了
    await expect
        .poll(() =>
            words
                .first()
                .locator('img')
                .evaluate((img: HTMLImageElement) => img.naturalWidth),
        )
        .toBeGreaterThan(0);
    await page.screenshot({
        path: testInfo.outputPath('lesson.png'),
        fullPage: true,
    });

    // 不必複製，直接選遊戲建立活動
    await page.locator('[data-test="lesson-activity"]').click();
    await page.getByRole('button', { name: /選擇題/ }).click();
    await page.getByRole('button', { name: '建立活動' }).click();
    await page.waitForURL('**/activities/*');
    await expect(
        page.getByRole('link', { name: /第 1 冊第 3 課/ }),
    ).toHaveAttribute('href', /\/curriculum\/id\/1\/3$/);
    const url = await page.locator('code').first().textContent();

    await page.context().clearCookies(); // 學生不登入
    await page.goto(new URL(url ?? '').pathname);
    await expect(
        page.getByRole('heading', {
            name: '第 1 冊第 3 課：Keluarga Saya 我的家人',
        }),
    ).toBeVisible();
    await page.getByRole('button', { name: '開始' }).click();
    await expect(page.locator('.kq-quiz__progress')).toHaveText('第 1 / 6 題');
    // 題目是插圖加中文意思
    await expect
        .poll(() =>
            page
                .locator('.kq-quiz__prompt img')
                .evaluate((img: HTMLImageElement) => img.naturalWidth),
        )
        .toBeGreaterThan(0);
    await page.screenshot({ path: testInfo.outputPath('quiz-textbook.png') });

    expect(errors).toEqual([]);
});

test('老師從教材挑詞建立自己的題組', async ({ page }, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/curriculum/id/1/1');
    await page.getByRole('link', { name: '挑詞建立題組' }).click();
    await page.waitForURL('**/sets/create**');

    // 從那一課進來時，整課已經選好，標題也自動填好
    const picker = page.locator('[data-test="textbook-picker"]');
    const count = page.locator('[data-test="picked-count"]');
    await expect(count).toHaveText('已選 6 個詞');
    await expect(page.getByLabel('標題')).toHaveValue(
        '第 1 冊第 1 課 我的名字',
    );

    // 再加入第 2 課，拿掉一個詞
    await picker.getByRole('checkbox', { name: /第 2 課/ }).check();
    await expect(count).toHaveText('已選 13 個詞');
    await picker.locator('label', { hasText: 'bapak guru' }).click();
    await expect(count).toHaveText('已選 12 個詞');
    await expect(page.getByLabel('標題')).toHaveValue('第 1 冊第 1、2 課複習');
    await picker.screenshot({ path: testInfo.outputPath('picker.png') });

    await page.getByRole('button', { name: '建立題組' }).click();
    await page.waitForURL('**/sets/*/edit');
    await expect(page.getByLabel('目標語', { exact: true })).toHaveCount(12);
    await expect(
        page.getByLabel('目標語', { exact: true }).first(),
    ).toHaveValue('teman');

    expect(errors).toEqual([]);
});
