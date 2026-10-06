import { expect, test } from '@playwright/test';
import {
    archerPrompt,
    archerToLandscape,
    collectErrors,
    createActivity,
    expectNothingClipped,
    shootBalloon,
} from './helpers';

// 射氣球（docs/SPEC.md 7.4、7.5、7.6）：弓箭手在左邊上下移動，往左拉弓、放開，箭水平射向往上飄的氣球，
// 射中帶著正解的那個。固定橫式版面，畫面是直的時候請學生轉成橫的。規則與打地鼠相同：
// 起始 3 顆星，射中正解 +1 換下一題，射中錯的 −1 留在同一題；題目射完重新洗牌再輪一次，直到時間到。
// 每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。
// 時間用 Playwright 的 clock 控制：瞄準時把時間停住，射出去之後快轉。

test.describe.serial('射氣球', () => {
    let playPath = '';

    test('老師為越南語題組建立射氣球活動', async ({ page }) => {
        const errors = collectErrors(page);

        // 已由 auth.setup.ts 以老師身分登入
        await page.goto('/sets');
        await page.getByRole('link', { name: /水果（越南語）/ }).click();
        await page.getByRole('link', { name: /選遊戲、建立活動/ }).click();
        playPath = await createActivity(page, /射氣球/);

        expect(errors).toEqual([]);
    });

    test('學生射中錯的扣星星、射中正解換題，輪完一輪再輪，時間到看到結果', async ({
        page,
    }, testInfo) => {
        // 每一箭都要等氣球飄上來，比預設的 30 秒久
        test.setTimeout(120_000);
        const errors = collectErrors(page);
        await page.context().clearCookies(); // 學生不登入
        await page.clock.install();

        await page.goto(playPath);
        await page.getByRole('button', { name: '開始' }).click();
        await archerToLandscape(page);

        const stars = page.locator('.kq-archer__stars');
        await expect(stars).toHaveText('★ 3');
        await expect(page.locator('.kq-archer__hint')).toBeVisible();

        // 鍵盤：↑ ↓ 移動弓箭手、空白鍵射出去。氣球還在地面下，射不到
        const now = await page.evaluate(() => Date.now());
        await page.clock.pauseAt(now + 10);
        await page.keyboard.press('ArrowUp');
        await expect(page.locator('.kq-archer__guide')).toBeVisible();
        await page.keyboard.press('Space');
        await expect(page.locator('.kq-archer__arrow')).toHaveCount(1);
        await expect(page.locator('.kq-archer__hint')).toBeHidden();
        await page.clock.runFor(1200);
        await expect(page.locator('.kq-archer__arrow')).toHaveCount(0);
        await page.clock.resume();
        await expect(stars).toHaveText('★ 3');

        // 第一題先射中一個錯的：扣一顆星，題目不變
        let entryId = await archerPrompt(page, null);
        await shootBalloon(page, { not: entryId });
        await expect(stars).toHaveText('★ 2');
        await expect(page.locator('.kq-archer__prompt')).toHaveAttribute(
            'data-entry-id',
            entryId,
        );

        // 越南文的疊加聲調符號完整顯示
        await expect(
            page.locator(
                `.kq-archer__balloon.is-flying[data-option-id="${entryId}"]`,
            ),
        ).toBeAttached({ timeout: 15_000 });
        await expectNothingClipped(
            page,
            '.kq-archer__body, .kq-archer__prompt',
        );
        await page.screenshot({
            path: testInfo.outputPath('archer-vietnamese.png'),
        });

        // 8 題都射中：每題 +1
        for (let i = 0; i < 8; i++) {
            if (i > 0) {
                entryId = await archerPrompt(page, entryId);
            }
            await shootBalloon(page, entryId);
        }
        await expect(stars).toHaveText('★ 10');

        // 射完一輪重新輪：第二輪的作答只加減星星，不影響成績
        await expect(page.locator('.kq-archer__lap')).toHaveText('第 2 輪');
        entryId = await archerPrompt(page, entryId);
        await shootBalloon(page, entryId);
        await expect(stars).toHaveText('★ 11');

        // 時間到
        await page.clock.fastForward('01:00');
        await expect(page.locator('.kq-archer__banner')).toContainText(
            '時間到！',
        );

        // 第一題第一次射中錯的，之後射中正解仍算錯（7.4）
        await expect(
            page.getByRole('heading', { name: '答對 7 / 8 題' }),
        ).toBeVisible();
        await expect(page.locator('.kq-player__score')).toHaveText('星星 11');
        await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);
        await page.screenshot({
            path: testInfo.outputPath('archer-results.png'),
        });

        expect(errors).toEqual([]);
    });

    test('老師在成績頁看到第一輪的成績與星星', async ({ page }) => {
        const errors = collectErrors(page);

        await page.goto(playPath.replace(/^\/p\//, '/activities/'));
        await page.getByRole('link', { name: '作答結果' }).click();
        await page.waitForURL('**/results');

        const row = page.locator('[data-test="attempt-row"]');
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('7 / 8');
        await expect(
            row.locator('[data-test="attempt-game-score"]'),
        ).toHaveText('11');

        expect(errors).toEqual([]);
    });
});
