import { expect, test } from '@playwright/test';
import { collectErrors, createActivity, expectNothingClipped } from './helpers';

// 字卡（docs/SPEC.md 7.5、7.6、S-03）：翻面、換卡，結束後顯示看過的張數；老師在成績頁看到看過哪幾張。
// 每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。

test.describe.serial('字卡', () => {
    let playPath = '';

    test('老師用教材的一課建立字卡，學生翻卡', async ({ page }, testInfo) => {
        const errors = collectErrors(page);

        // 已由 auth.setup.ts 以老師身分登入
        await page.goto('/curriculum/id/1/3');
        await page.locator('[data-test="lesson-activity"]').click();
        playPath = await createActivity(page, /字卡/);

        await page.context().clearCookies(); // 學生不登入
        await page.goto(playPath);
        await page.getByRole('button', { name: '開始' }).click();

        const progress = page.locator('.kq-cards__progress');
        const inner = page.locator('.kq-cards__inner');
        await expect(progress).toHaveText('第 1 / 6 張');
        // 先顯示題目那一面：插圖與中文意思
        await expect
            .poll(() =>
                page
                    .locator('.kq-cards__side--first img')
                    .evaluate((img: HTMLImageElement) => img.naturalWidth),
            )
            .toBeGreaterThan(0);
        await page.screenshot({ path: testInfo.outputPath('cards-front.png') });

        // 背面真的看不到（有的 WebKit 不支援 backface-visibility，背面會鏡像蓋在正面上）
        await expect(page.locator('.kq-cards__side--second')).toBeHidden();

        // 點卡片翻面，看到印尼語
        await page.locator('.kq-cards__card').click();
        await expect(page.locator('.kq-cards__side--first')).toBeHidden();
        await expect(inner).toHaveClass(/is-flipped/);
        await expect(page.locator('.kq-cards__side--second')).toHaveAttribute(
            'aria-hidden',
            'false',
        );
        await page.waitForTimeout(600); // 等翻面動畫結束再截圖
        await page.screenshot({ path: testInfo.outputPath('cards-back.png') });

        // 換卡後回到題目那一面；再翻最後兩張
        for (let i = 2; i <= 6; i++) {
            await page.getByRole('button', { name: '下一張 →' }).click();
            await expect(progress).toHaveText(`第 ${i} / 6 張`);
            await expect(inner).not.toHaveClass(/is-flipped/);
            if (i >= 5) {
                await page.getByRole('button', { name: '翻面' }).click();
                await expect(inner).toHaveClass(/is-flipped/);
            }
        }

        // 回到看過的卡再翻一次，不重複算
        await page.getByRole('button', { name: '← 上一張' }).click();
        await page.getByRole('button', { name: '翻面' }).click();
        await page.getByRole('button', { name: '下一張 →' }).click();

        await page.getByRole('button', { name: '完成 ✓' }).click();
        await expect(
            page.getByRole('heading', { name: '看過 3 / 6 張' }),
        ).toBeVisible();
        await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);
        await page.screenshot({
            path: testInfo.outputPath('cards-results.png'),
        });

        expect(errors).toEqual([]);
    });

    test('老師在成績頁看到學生看過哪幾張', async ({ page }) => {
        const errors = collectErrors(page);

        await page.goto(playPath.replace(/^\/p\//, '/activities/'));
        await page.getByRole('link', { name: '作答結果' }).click();
        await page.waitForURL('**/results');

        await expect(page.getByText('逐題統計')).toBeVisible();
        const row = page.locator('[data-test="attempt-row"]');
        await expect(row).toHaveCount(1);
        await row.click();
        const detail = page.locator('[data-test="attempt-detail"] > li');
        await expect(detail).toHaveCount(6);
        await expect(detail.filter({ hasText: '沒看過' })).toHaveCount(3);

        expect(errors).toEqual([]);
    });
});

test('越南文字卡：聲調符號完整顯示，可以滑動與用鍵盤換卡', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);

    await page.goto('/sets');
    await page.getByRole('link', { name: /水果（越南語）/ }).click();
    await page.getByRole('link', { name: /選遊戲、建立活動/ }).click();
    // 先顯示答案那一面（越南文），翻面看中文
    const playPath = await createActivity(page, /字卡/, () =>
        page
            .getByLabel('先顯示哪一面')
            .selectOption('back')
            .then(() => {}),
    );

    await page.context().clearCookies();
    await page.goto(playPath);
    await page.getByRole('button', { name: '開始' }).click();

    const progress = page.locator('.kq-cards__progress');
    await expect(progress).toHaveText('第 1 / 8 張');
    await expect(page.locator('.kq-cards__side--first')).toContainText(/quả/);
    await expectNothingClipped(page, '.kq-cards__text');
    await page.screenshot({
        path: testInfo.outputPath('cards-vietnamese.png'),
    });

    // 向左滑換下一張，滑動不算翻面
    const box = await page.locator('.kq-cards__card').boundingBox();
    if (!box) {
        throw new Error('找不到字卡');
    }
    const y = box.y + box.height / 2;
    await page.mouse.move(box.x + box.width * 0.75, y);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.25, y, { steps: 6 });
    await page.mouse.up();
    await expect(progress).toHaveText('第 2 / 8 張');
    await expect(page.locator('.kq-cards__inner')).not.toHaveClass(
        /is-flipped/,
    );

    // 鍵盤：方向鍵換卡，空白鍵翻面
    await page.keyboard.press('ArrowRight');
    await expect(progress).toHaveText('第 3 / 8 張');
    await page.keyboard.press('ArrowLeft');
    await expect(progress).toHaveText('第 2 / 8 張');
    await page.keyboard.press(' ');
    await expect(page.locator('.kq-cards__inner')).toHaveClass(/is-flipped/);

    expect(errors).toEqual([]);
});
