import { expect, test } from '@playwright/test';
import { collectErrors, expectNothingClipped } from './helpers';

// 老師建立活動、學生玩選擇題的完整流程（docs/SPEC.md M1 驗收 2、4、6；7.6）。
// 每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。

test.describe.serial('選擇題', () => {
    let playPath = '';
    let correct = -1;

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

        // T-08：建立之前先在新分頁試玩
        const [preview] = await Promise.all([
            page.waitForEvent('popup'),
            page.getByRole('link', { name: /先試玩/ }).click(),
        ]);
        await expect(
            preview.getByText('預覽模式：不會留下作答紀錄'),
        ).toBeVisible();
        await preview.getByRole('button', { name: '開始' }).click();
        await expect(preview.locator('.kq-quiz__progress')).toHaveText(
            '第 1 / 5 題',
        );
        await preview.close();

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

        const heading = page.getByRole('heading', {
            name: /^答對 \d \/ 5 題$/,
        });
        await expect(heading).toBeVisible();
        correct = Number((await heading.textContent())?.match(/\d/)?.[0]);
        await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);
        await page.screenshot({ path: testInfo.outputPath('results.png') });

        expect(errors).toEqual([]);
    });

    test('老師在成績頁看到這次作答（T-11）', async ({ page }, testInfo) => {
        const errors = collectErrors(page);

        await page.goto(playPath.replace(/^\/p\//, '/activities/'));
        await page.getByRole('link', { name: '作答結果' }).click();
        await page.waitForURL('**/results');

        await expect(page.locator('[data-test="question-result"]')).toHaveCount(
            5,
        );
        const row = page.locator('[data-test="attempt-row"]');
        await expect(row).toHaveCount(1);
        await expect(row).toContainText(`${correct} / 5`);

        await row.click();
        const detail = page.locator('[data-test="attempt-detail"] > li');
        await expect(detail).toHaveCount(5);
        await expect(detail.filter({ hasText: '答對' })).toHaveCount(correct);
        await page.screenshot({
            path: testInfo.outputPath('teacher-results.png'),
            fullPage: true,
        });

        // 老師可以刪除一次作答（例如自己試玩留下的），先確認
        page.once('dialog', (dialog) => void dialog.accept());
        await page.locator('[data-test="attempt-delete"]').click();
        await expect(row).toHaveCount(0);
        await expect(page.getByText('已刪除這次作答')).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('學生可以檢舉活動', async ({ page }) => {
        const errors = collectErrors(page);
        await page.context().clearCookies(); // 學生不登入

        // 檢舉（SPEC S-07）：開始畫面最下面的小連結，送給網站管理員
        await page.goto(playPath);
        await page.getByRole('button', { name: '檢舉這個活動' }).click();
        await expect(
            page.getByRole('heading', { name: '檢舉這個活動' }),
        ).toBeVisible();
        await page.getByLabel('這個活動有什麼問題？').fill('測試：檢舉的流程');
        await page.getByRole('button', { name: '送出' }).click();
        await expect(
            page.getByRole('heading', {
                name: '已經送出，謝謝你。管理員會盡快處理。',
            }),
        ).toBeVisible();
        await page.getByRole('button', { name: '返回' }).click();
        await expect(page.getByRole('button', { name: '開始' })).toBeVisible();

        expect(errors).toEqual([]);
    });
});
