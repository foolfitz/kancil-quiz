import { expect, test } from '@playwright/test';
import {
    collectErrors,
    createActivity,
    expectNothingClipped,
    whackAnswer,
    whackPrompt,
} from './helpers';

// 打地鼠（docs/SPEC.md 7.4、7.5、7.6）：起始 3 顆星，打對 +1 換下一題，打錯 −1 留在同一題；
// 題目打完重新洗牌再輪一次，直到時間到。成績只看第一輪每題第一次打的。
// 每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。
// 時間用 Playwright 的 clock 快轉，不必真的等 60 秒。

test.describe.serial('打地鼠', () => {
    let playPath = '';

    test('老師為越南語題組建立打地鼠活動', async ({ page }) => {
        const errors = collectErrors(page);

        // 已由 auth.setup.ts 以老師身分登入
        await page.goto('/sets');
        await page.getByRole('link', { name: /水果（越南語）/ }).click();
        await page.getByRole('link', { name: /選遊戲、建立活動/ }).click();
        playPath = await createActivity(page, /打地鼠/);

        expect(errors).toEqual([]);
    });

    test('學生打錯扣星星、打對換題，輪完一輪再輪，時間到看到結果', async ({
        page,
    }, testInfo) => {
        const errors = collectErrors(page);
        await page.context().clearCookies(); // 學生不登入
        await page.clock.install();

        await page.goto(playPath);
        await page.getByRole('button', { name: '開始' }).click();

        const stars = page.locator('.kq-whack__stars');
        await expect(stars).toHaveText('★ 3');
        await expect(page.locator('.kq-whack__timer')).toHaveText(
            /^(60|59) 秒$/,
        );
        await expect(page.locator('.kq-whack__hole')).toHaveCount(9);

        // 第一題先打錯一次：扣一顆星，題目不變
        let entryId = await whackPrompt(page, null);
        const wrong = page
            .locator(`.kq-whack__hole.is-up:not([data-option-id="${entryId}"])`)
            .first();
        await wrong.click();
        await expect(stars).toHaveText('★ 2');
        await expect(page.locator('.kq-whack__hole.is-miss')).toHaveCount(1);
        await expect(page.locator('.kq-whack__prompt')).toHaveAttribute(
            'data-entry-id',
            entryId,
        );

        // 越南文的疊加聲調符號完整顯示
        await expect(
            page.locator(`.kq-whack__hole.is-up[data-option-id="${entryId}"]`),
        ).toBeVisible({ timeout: 15_000 });
        await expectNothingClipped(page, '.kq-whack__sign, .kq-whack__prompt');
        await page.screenshot({
            path: testInfo.outputPath('whack-vietnamese.png'),
        });

        // 8 題都打對：每題 +1
        for (let i = 0; i < 8; i++) {
            if (i > 0) {
                entryId = await whackPrompt(page, entryId);
            }
            await whackAnswer(page, entryId);
        }
        await expect(stars).toHaveText('★ 10');

        // 打完一輪重新輪：第二輪的作答只加減星星，不影響成績
        await expect(page.locator('.kq-whack__lap')).toHaveText('第 2 輪');
        entryId = await whackPrompt(page, entryId);
        await whackAnswer(page, entryId);
        await expect(stars).toHaveText('★ 11');

        // 時間到
        await page.clock.fastForward('01:00');
        await expect(page.locator('.kq-whack__banner')).toContainText(
            '時間到！',
        );
        await expect(page.locator('.kq-whack__banner')).toContainText('★ 11');

        // 第一題第一次打錯，之後打對仍算錯（7.4）
        await expect(
            page.getByRole('heading', { name: '答對 7 / 8 題' }),
        ).toBeVisible();
        await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);
        await expect(page.locator('.kq-player__review-item')).toHaveCount(1);
        await page.screenshot({
            path: testInfo.outputPath('whack-results.png'),
        });

        expect(errors).toEqual([]);
    });

    test('老師在成績頁看到第一輪的成績', async ({ page }) => {
        const errors = collectErrors(page);

        await page.goto(playPath.replace(/^\/p\//, '/activities/'));
        await page.getByRole('link', { name: '作答結果' }).click();
        await page.waitForURL('**/results');

        const row = page.locator('[data-test="attempt-row"]');
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('7 / 8');
        await row.click();
        const detail = page.locator('[data-test="attempt-detail"] > li');
        await expect(detail).toHaveCount(8);
        await expect(detail.filter({ hasText: '答錯' })).toHaveCount(1);

        expect(errors).toEqual([]);
    });
});
