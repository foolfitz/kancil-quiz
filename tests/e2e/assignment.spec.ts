import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { collectErrors, createActivity } from './helpers';

// 要學生輸入名字或座號的活動（docs/SPEC.md 3.4、T-14、S-04、M3 驗收）：
// 老師發出連結，學生輸入座號後作答，老師在結果頁看到每位學生的答對題數與最常答錯的題目。

async function playQuiz(page: Page): Promise<number> {
    for (let i = 1; i <= 5; i++) {
        await expect(page.locator('.kq-quiz__progress')).toHaveText(
            `第 ${i} / 5 題`,
        );
        await page.locator('.kq-quiz__option').first().click();
        await expect(page.locator('.kq-quiz__feedback')).toHaveText(
            /答對了|答錯了/,
        );
    }
    const heading = page.getByRole('heading', { name: /^答對 \d \/ 5 題$/ });
    await expect(heading).toBeVisible();
    return Number((await heading.textContent())?.match(/\d/)?.[0]);
}

test.describe.serial('要輸入名字或座號的活動', () => {
    let playPath = '';
    let firstScore = -1;

    test('老師建立活動，預設今天起開放一週', async ({ page }) => {
        const errors = collectErrors(page);

        await page.goto('/sets');
        await page.getByRole('link', { name: /打招呼（越南語）/ }).click();
        await page.getByRole('link', { name: /選遊戲、建立活動/ }).click();

        playPath = await createActivity(page, /選擇題/, async () => {
            await expect(
                page.getByLabel('開放時間', { exact: true }),
            ).toHaveValue(/T00:00$/);
            await expect(
                page.getByLabel('截止時間', { exact: true }),
            ).toHaveValue(/T23:59$/);
            await page.getByLabel('學生要先輸入名字或座號').check();
        });

        const settings = page.locator('[data-test="activity-settings"]');
        await expect(settings).toContainText('學生要先輸入');
        await expect(settings).toContainText(/\d{4}\/\d{2}\/\d{2} 23:59/);
        await expect(page.locator('[data-test="activity-status"]')).toHaveText(
            '進行中',
        );

        expect(errors).toEqual([]);
    });

    test('學生輸入座號才能開始，可以重玩，也可以換人', async ({
        browser,
    }, testInfo) => {
        test.slow(); // 玩三輪
        const context = await browser.newContext({ locale: 'zh-TW' });
        const page = await context.newPage();
        const errors = collectErrors(page);

        await page.goto(playPath);
        await expect(page.getByText(/截止$/)).toBeVisible();
        const start = page.getByRole('button', { name: '開始' });
        await expect(start).toBeDisabled();
        await page.getByLabel('你的名字或座號').fill('５');
        await page.screenshot({ path: testInfo.outputPath('label.png') });

        // 連不上伺服器時不讓學生玩：學生會以為交了作業
        await page.route('**/api/v1/activities/*/attempts', (route) =>
            route.abort(),
        );
        await start.click();
        await expect(
            page.getByRole('heading', { name: '沒有連上伺服器' }),
        ).toBeVisible();
        await page.unroute('**/api/v1/activities/*/attempts');
        errors.splice(0); // 刻意擋下的請求會在 console 留下錯誤
        await page.getByRole('button', { name: '再試一次' }).click();

        firstScore = await playQuiz(page);
        await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);
        await expect(page.locator('.kq-player__game')).toHaveText('５');

        // 再玩一次沿用同一個名字
        await page.getByRole('button', { name: '再玩一次' }).click();
        await playQuiz(page);

        // 換人：下一位學生重新輸入
        await page.getByRole('button', { name: '換人' }).click();
        await expect(page.getByLabel('你的名字或座號')).toHaveValue('');
        await page.getByLabel('你的名字或座號').fill('Andi');
        await page.getByLabel('你的名字或座號').press('Enter');
        await playQuiz(page);

        expect(errors).toEqual([]);
        await context.close();
    });

    test('老師看到每位學生的成績，並下載 CSV', async ({ page }, testInfo) => {
        const errors = collectErrors(page);

        await page.goto(
            `${playPath.replace(/^\/p\//, '/activities/')}/results`,
        );
        const students = page.locator('[data-test="student-row"]');
        await expect(students).toHaveCount(2);
        // 全形的「５」以「5」儲存；成績以第一次玩完的為準
        await expect(students.first()).toContainText('5');
        await expect(students.first()).toContainText(`${firstScore} / 5`);
        await expect(students.first()).toContainText('2 次');
        await expect(students.nth(1)).toContainText('Andi');
        await expect(page.locator('[data-test="question-result"]')).toHaveCount(
            5,
        );

        await students.first().click();
        await expect(
            page.locator('[data-test="attempt-detail"] > li'),
        ).toHaveCount(5);
        await page.screenshot({
            path: testInfo.outputPath('students.png'),
            fullPage: true,
        });

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: /下載 CSV/ }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.csv$/);
        const csv = await readFile((await download.path()) ?? '', 'utf8');
        expect(csv.replace(/^﻿/, '').split(/\r?\n/)[0]).toBe(
            '名字或座號,答對（第一次玩完）,題數,最高答對,玩了幾次,玩完幾次,最後作答時間',
        );
        expect(csv).toContain(`\n5,${firstScore},5,`);

        expect(errors).toEqual([]);
    });

    test('立即截止後，學生不能再開始', async ({ page, browser }) => {
        const errors = collectErrors(page);

        // 截止前就打開頁面的學生，按「開始」時由伺服器擋下
        const context = await browser.newContext({ locale: 'zh-TW' });
        const student = await context.newPage();
        await student.goto(playPath);
        await expect(student.getByLabel('你的名字或座號')).toBeVisible();

        await page.goto(playPath.replace(/^\/p\//, '/activities/'));
        page.once('dialog', (dialog) => void dialog.accept());
        await page.getByRole('button', { name: '立即截止' }).click();
        await expect(page.locator('[data-test="activity-status"]')).toHaveText(
            '已截止',
        );

        await student.getByLabel('你的名字或座號').fill('7');
        await student.getByRole('button', { name: '開始' }).click();
        await expect(student.getByText('這個活動已經截止')).toBeVisible();

        // 重新整理後，一開始就顯示已經截止
        await student.reload();
        await expect(student.getByText('這個活動已經截止。')).toBeVisible();
        await context.close();

        expect(errors).toEqual([]);
    });
});
