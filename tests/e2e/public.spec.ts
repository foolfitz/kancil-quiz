import { expect, test } from '@playwright/test';
import { collectErrors } from './helpers';

// 不需登入的教材（docs/SPEC.md S-06）：訪客從首頁找到一課，直接玩，不留作答紀錄。
// 資料來自 DemoSeeder：印尼語第 1 冊第 1 到 4 課。
test.use({ storageState: { cookies: [], origins: [] } });

test('訪客從首頁找到一課，直接玩選擇題', async ({ page }, testInfo) => {
    const errors = collectErrors(page);

    await page.goto('/');
    await expect(
        page.getByRole('heading', {
            name: '新住民語文教材的每一課，都能直接變成遊戲',
        }),
    ).toBeVisible();
    // 伺服器輸出的骨架只給不執行 JS 的程式看，Vue 掛上後就換掉了
    await expect(page.locator('.kq-skeleton')).toHaveCount(0);
    const lessons = page.locator('[data-test="curriculum-lesson"]');
    await expect(lessons).toHaveCount(4);
    await page.screenshot({
        path: testInfo.outputPath('home.png'),
        fullPage: true,
    });

    await lessons.filter({ hasText: 'Keluarga Saya' }).click();
    await page.waitForURL('**/curriculum/id/1/3');
    await expect(page).toHaveTitle(
        /^印尼語第 1 冊第 3 課：Keluarga Saya 我的家人 - /,
    );
    await expect(page.locator('[data-test="lesson-words"] > li')).toHaveCount(
        6,
    );
    // 老師的功能要登入，訪客看到的是登入的說明
    await expect(page.locator('[data-test="lesson-activity"]')).toHaveCount(0);
    await expect(page.locator('[data-test="lesson-teacher"]')).toBeVisible();
    await page.screenshot({
        path: testInfo.outputPath('lesson-guest.png'),
        fullPage: true,
    });

    await page
        .locator('[data-test="lesson-play"]', { hasText: '選擇題' })
        .click();
    await page.waitForURL('**/curriculum/id/1/3/play/quiz');
    await expect(
        page.getByRole('heading', {
            name: '第 1 冊第 3 課：Keluarga Saya 我的家人',
        }),
    ).toBeVisible();
    await expect(page.getByText('預覽模式')).toHaveCount(0);
    // 人氣統計（SPEC A-04）：開始與玩完各送一次，只帶遊戲與事件
    const plays: unknown[] = [];
    page.on('request', (request) => {
        if (request.url().endsWith('/api/v1/curriculum/id/1/3/plays')) {
            plays.push(request.postDataJSON());
        }
    });
    await page.getByRole('button', { name: '開始' }).click();
    await expect.poll(() => plays).toEqual([{ game: 'quiz', event: 'start' }]);

    for (let i = 1; i <= 6; i++) {
        await expect(page.locator('.kq-quiz__progress')).toHaveText(
            `第 ${i} / 6 題`,
        );
        await page.locator('.kq-quiz__option').first().click();
        await expect(page.locator('.kq-quiz__feedback')).toHaveText(
            /答對了|答錯了/,
        );
    }

    // 不上傳，也就不必提示「沒有上傳成功」
    await expect(
        page.getByRole('heading', { name: /^答對 \d \/ 6 題$/ }),
    ).toBeVisible();
    await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);
    await expect
        .poll(() => plays)
        .toEqual([
            { game: 'quiz', event: 'start' },
            { game: 'quiz', event: 'finish' },
        ]);
    await page.screenshot({ path: testInfo.outputPath('trial-results.png') });

    await page.getByRole('link', { name: '← 回到這一課' }).click();
    await page.waitForURL('**/curriculum/id/1/3');

    expect(errors).toEqual([]);
});
