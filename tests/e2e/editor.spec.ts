import { expect, test } from '@playwright/test';
import { wavFile } from './files';
import { collectErrors } from './helpers';

// 題組編輯頁：媒體的署名（docs/SPEC.md 第 9 節）。每次從教材第 3 課挑詞建立新的題組：
// 插圖是教材的（別人上傳的，只能看），發音由測試上傳（自己的，可以改署名）。

test('老師看到教材插圖的署名，為自己上傳的發音填署名', async ({
    page,
}, testInfo) => {
    test.slow(); // 上傳的音檔要等伺服器轉檔
    const errors = collectErrors(page);

    await page.goto('/curriculum/id/1/3');
    await page.getByRole('link', { name: '挑詞建立題組' }).click();
    await page.getByRole('button', { name: '建立題組' }).click();
    await page.waitForURL('**/sets/*/edit');

    const credits = page.locator('[data-test="media-credits"]');
    await expect(credits).toHaveCount(6);
    const first = credits.first();
    const summary = first.locator('[data-test="media-credits-summary"]');
    await expect(summary).toHaveText('：Kancil Quiz・CC BY 4.0・AI 生成');
    await first.locator('summary').click();
    await expect(first).toContainText('別人上傳的，不能在這裡修改');

    // 上傳發音要先勾權利聲明；上傳後作者是老師、授權是題組的
    await page.getByTitle('上傳發音').first().click();
    await expect(page.getByRole('alert')).toContainText(
        '請先勾選上方的權利聲明',
    );
    await page.getByLabel(/我有權分享這次上傳的音檔與圖片/).check();
    await page.locator('input[type=file]').first().setInputFiles(wavFile());
    await expect(page.getByTitle('播放發音')).toHaveCount(1);
    await expect(summary).toHaveText(
        '：示範老師・CC BY 4.0；Kancil Quiz・CC BY 4.0・AI 生成',
    );

    await first.getByLabel('發音的作者').fill('王老師');
    await first.getByLabel('發音的出處').fill('自行錄製');
    await first.getByLabel('發音的授權').selectOption('CC0-1.0');
    await expect(summary).toHaveText(
        '：王老師・CC0 1.0・自行錄製；Kancil Quiz・CC BY 4.0・AI 生成',
    );
    await page
        .locator('ol')
        .filter({ has: first })
        .locator('> li')
        .first()
        .screenshot({ path: testInfo.outputPath('credits.png') });

    await page.getByRole('button', { name: '儲存', exact: true }).click();
    await expect(page.getByText('已儲存').first()).toBeVisible();
    await page.reload();
    await expect(summary).toHaveText(
        '：王老師・CC0 1.0・自行錄製；Kancil Quiz・CC BY 4.0・AI 生成',
    );
    await expect(page.getByTitle('播放發音')).toHaveCount(1);

    // 手機寬度也放得下
    await page.setViewportSize({ width: 390, height: 844 });
    await credits.first().locator('summary').click();
    await page.screenshot({ path: testInfo.outputPath('editor-phone.png') });

    expect(errors).toEqual([]);
});
