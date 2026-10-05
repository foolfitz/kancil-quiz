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

    // 換遊戲：只列出這一課能玩的其他遊戲，不含正在玩的
    const otherGames = page.getByRole('navigation', { name: '換一個遊戲' });
    await expect(otherGames.getByRole('link', { name: '選擇題' })).toHaveCount(
        0,
    );
    await otherGames.getByRole('link', { name: '迷宮問答' }).click();
    await page.waitForURL('**/curriculum/id/1/3/play/maze-quiz');
    await expect(
        page
            .getByRole('navigation', { name: '換一個遊戲' })
            .getByRole('link', { name: '選擇題' }),
    ).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('trial-switch.png') });

    await page.getByRole('link', { name: '← 回到這一課' }).click();
    await page.waitForURL('**/curriculum/id/1/3');

    expect(errors).toEqual([]);
});

test('老師登入頁、隱私權政策與使用條款', async ({ page }, testInfo) => {
    const errors = collectErrors(page);

    await page.goto('/');
    await page.getByRole('link', { name: '老師登入' }).first().click();
    await page.waitForURL('**/login');
    // E2E 沒有設定 Google 的 OAuth 用戶端；密碼登入收在下面，預設不展開
    await expect(page.getByText('Google 登入還沒有開放')).toBeVisible();
    await expect(page.locator('input[name=password]')).toBeHidden();
    await page.screenshot({ path: testInfo.outputPath('login.png') });

    await page.getByRole('link', { name: '隱私權政策' }).click();
    await page.waitForURL('**/privacy');
    await expect(
        page.getByRole('heading', { name: '隱私權政策', level: 1 }),
    ).toBeVisible();
    await page.getByRole('link', { name: '使用條款' }).first().click();
    await page.waitForURL('**/terms');
    await expect(
        page.getByRole('heading', { name: '使用條款', level: 1 }),
    ).toBeVisible();
    await page.screenshot({
        path: testInfo.outputPath('terms.png'),
        fullPage: true,
    });

    expect(errors).toEqual([]);
});
