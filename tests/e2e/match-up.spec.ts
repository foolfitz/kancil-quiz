import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { collectErrors, createActivity, expectNothingClipped } from './helpers';

// 配對（docs/SPEC.md 7.4、7.5、7.6）：拖曳或點選配對，放錯的卡片退回，每題以第一次放的卡片計分；
// 題目多時分頁。每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。

async function dragTo(page: Page, from: Locator, to: Locator): Promise<void> {
    const a = await from.boundingBox();
    const b = await to.boundingBox();
    if (!a || !b) {
        throw new Error('找不到要拖曳的位置');
    }
    await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
    await page.mouse.down();
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, {
        steps: 10,
    });
    await page.mouse.up();
}

// 配好這一頁：雙數題用拖曳，單數題用點選（先點卡片，再點題目旁的空格）
async function matchPage(page: Page): Promise<void> {
    const rows = page.locator('.kq-match__row');
    const ids = await rows.evaluateAll((elements) =>
        elements.map((el) => (el as HTMLElement).dataset.entryId ?? ''),
    );
    for (const [i, id] of ids.entries()) {
        const card = page.locator(`.kq-match__pool [data-entry-id="${id}"]`);
        const row = page.locator(`.kq-match__row[data-entry-id="${id}"]`);
        if (i % 2 === 0) {
            await dragTo(page, card, row);
        } else {
            await card.click();
            await row.locator('.kq-match__drop').click();
        }
        await expect(row).toHaveClass(/is-matched/);
    }
}

test.describe.serial('配對', () => {
    let playPath = '';

    test('老師為越南語題組建立配對活動', async ({ page }) => {
        const errors = collectErrors(page);

        // 已由 auth.setup.ts 以老師身分登入
        await page.goto('/sets');
        await page.getByRole('link', { name: /水果（越南語）/ }).click();
        await page.getByRole('link', { name: /選遊戲、建立活動/ }).click();
        playPath = await createActivity(page, /配對/);

        expect(errors).toEqual([]);
    });

    test('學生拖曳與點選配對，放錯的卡片退回', async ({ page }, testInfo) => {
        const errors = collectErrors(page);
        await page.context().clearCookies(); // 學生不登入

        await page.goto(playPath);
        await page.getByRole('button', { name: '開始' }).click();

        // 8 題、每頁最多 6 組：分成 4、4 兩頁
        const progress = page.locator('.kq-match__progress');
        await expect(progress).toHaveText('第 1 / 2 頁｜配好 0 / 4 組');
        await expectNothingClipped(
            page,
            '.kq-match__card, .kq-match__prompt, .kq-match__text',
        );
        await page.screenshot({
            path: testInfo.outputPath('match-vietnamese.png'),
        });

        // 先放錯一次：把別題的卡片拖到第一題，卡片退回
        const first = page.locator('.kq-match__row').first();
        const firstId = await first.getAttribute('data-entry-id');
        const wrong = page
            .locator(
                `.kq-match__pool .kq-match__card:not([data-entry-id="${firstId}"])`,
            )
            .first();
        await dragTo(page, wrong, first);
        await expect(page.locator('.kq-match__feedback')).toHaveText(
            '✗ 不對，再試一次',
        );
        await expect(first).not.toHaveClass(/is-matched/);
        await expect(wrong).toBeVisible();

        await matchPage(page);
        await expect(page.locator('.kq-match__feedback')).toHaveText(
            '✓ 這一頁完成了！',
        );
        await expect(progress).toHaveText('第 2 / 2 頁｜配好 0 / 4 組');
        await matchPage(page);

        // 第一題第一次放錯，之後放對仍算錯（7.4）
        await expect(
            page.getByRole('heading', { name: '答對 7 / 8 題' }),
        ).toBeVisible();
        await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);
        await expect(page.locator('.kq-player__review-item')).toHaveCount(1);
        await page.screenshot({
            path: testInfo.outputPath('match-results.png'),
        });

        expect(errors).toEqual([]);
    });

    test('老師在成績頁看到學生放錯的卡片', async ({ page }) => {
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
        const wrong = detail.filter({ hasText: '答錯' });
        await expect(wrong).toHaveCount(1);
        await expect(wrong).toContainText(/選了：\s*quả/);

        expect(errors).toEqual([]);
    });
});

test('教材的一課用配對：看圖配印尼語', async ({ page }, testInfo) => {
    const errors = collectErrors(page);

    await page.goto('/curriculum/id/1/3');
    await page.locator('[data-test="lesson-activity"]').click();
    const playPath = await createActivity(page, /配對/);

    await page.context().clearCookies();
    await page.goto(playPath);
    await page.getByRole('button', { name: '開始' }).click();

    // 6 個詞，一頁就放得下；題目是插圖與中文意思
    await expect(page.locator('.kq-match__progress')).toHaveText(
        '配好 0 / 6 組',
    );
    await expect
        .poll(() =>
            page
                .locator('.kq-match__prompt img')
                .evaluateAll((images) =>
                    images.every(
                        (img) => (img as HTMLImageElement).naturalWidth > 0,
                    ),
                ),
        )
        .toBe(true);
    await expectNothingClipped(page, '.kq-match__card, .kq-match__prompt');
    await page.screenshot({ path: testInfo.outputPath('match-textbook.png') });

    await matchPage(page);
    await expect(
        page.getByRole('heading', { name: '答對 6 / 6 題' }),
    ).toBeVisible();

    expect(errors).toEqual([]);
});
