import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// 老師建立活動、學生玩選擇題的完整流程（docs/SPEC.md M1 驗收 2、4、6；7.6）。
// 每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。

function collectErrors(page: Page): string[] {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    return errors;
}

// 文字沒有被容器截切：內容高度不超過容器（越南文的疊加聲調符號最容易出問題）。
async function expectNothingClipped(
    page: Page,
    selector: string,
): Promise<void> {
    const clipped = await page
        .locator(selector)
        .evaluateAll((elements) =>
            elements
                .filter(
                    (el) =>
                        el.scrollHeight > el.clientHeight + 1 ||
                        el.scrollWidth > el.clientWidth + 1,
                )
                .map((el) => el.textContent),
        );
    expect(clipped).toEqual([]);
}

test.describe.serial('選擇題', () => {
    let playPath = '';

    test('老師登入、為題組選遊戲並建立活動', async ({ page }) => {
        const errors = collectErrors(page);

        // 已由 auth.setup.ts 以老師身分登入
        await page.goto('/sets');
        await page.getByRole('link', { name: /打招呼（越南語）/ }).click();
        await expect(page.locator('textarea').first()).toHaveValue(
            '「謝謝」的越南語是？',
        );

        await page.getByRole('link', { name: /選遊戲、建立活動/ }).click();
        await page.getByRole('button', { name: /選擇題/ }).click();
        await page.getByRole('button', { name: '建立活動' }).click();
        await page.waitForURL('**/activities/*');

        const url = await page.locator('code').first().textContent();
        playPath = new URL(url ?? '').pathname;
        expect(playPath).toMatch(/^\/p\/[0-9A-HJKMNP-TV-Z]{26}$/);
        await expect(page.locator('svg').first()).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('學生不登入就能玩完一輪，並看到結果', async ({ page }, testInfo) => {
        const errors = collectErrors(page);
        await page.context().clearCookies(); // 學生不登入

        await page.goto(playPath);
        await expect(
            page.getByRole('heading', { name: '打招呼（越南語）' }),
        ).toBeVisible();
        await page.getByRole('button', { name: '開始' }).click();

        for (let i = 1; i <= 5; i++) {
            await expect(page.locator('.kq-quiz__progress')).toHaveText(
                `第 ${i} / 5 題`,
            );
            await expectNothingClipped(page, '.kq-quiz__option');
            if (i === 4) {
                // 「Chúc mừng năm mới」：ừ、ớ 是疊加的聲調符號
                await page.screenshot({
                    path: testInfo.outputPath('quiz-vietnamese.png'),
                });
            }
            await page.locator('.kq-quiz__option').first().click();
            await expect(page.locator('.kq-quiz__feedback')).toHaveText(
                /答對了|答錯了/,
            );
        }

        await expect(
            page.getByRole('heading', { name: /^答對 \d \/ 5 題$/ }),
        ).toBeVisible();
        await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);
        await page.screenshot({ path: testInfo.outputPath('results.png') });

        expect(errors).toEqual([]);
    });
});
