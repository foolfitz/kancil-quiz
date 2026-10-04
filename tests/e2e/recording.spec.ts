import { expect, test } from '@playwright/test';
import { collectErrors } from './helpers';

// 老師在瀏覽器中錄音（docs/SPEC.md T-06）：逐詞錄音與單一個詞的錄音。
// Chromium 用假的麥克風（playwright.config.ts）；WebKit 沒有假麥克風，iPad 上要用真機試。

test('老師逐詞錄音，也可以只錄一個詞', async ({
    page,
    browserName,
}, testInfo) => {
    test.skip(browserName !== 'chromium', '只有 Chromium 有假的麥克風');
    test.slow(); // 每段錄音都要等伺服器轉檔
    const errors = collectErrors(page);

    // 從教材第 1 課挑詞建立新的題組，每次執行都從沒有發音的詞開始
    await page.goto('/curriculum/id/1/1');
    await page.getByRole('link', { name: '挑詞建立題組' }).click();
    await page.getByRole('button', { name: '建立題組' }).click();
    await page.waitForURL('**/sets/*/edit');
    const words = page.getByLabel('目標語', { exact: true });
    await expect(words).toHaveCount(6);
    const playButtons = page.getByTitle('播放發音');
    await expect(playButtons).toHaveCount(0);

    // 要先勾選權利聲明
    await page.getByRole('button', { name: '逐詞錄音' }).click();
    await expect(page.getByRole('alert')).toContainText(
        '請先勾選上方的權利聲明',
    );
    await page.getByLabel(/我有權分享這次上傳的音檔與圖片/).check();

    await page.getByRole('button', { name: '逐詞錄音' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('第 1 / 6 個');
    const first = await dialog
        .locator('[data-test="sequential-word"]')
        .textContent();
    expect(first).toBe(await words.first().inputValue());

    // 用按鈕：錄音、停止、試聽、採用
    await dialog.getByRole('button', { name: '開始錄音' }).click();
    await expect(dialog.locator('[data-test="recording-clock"]')).toHaveText(
        /^0:01 /,
    );
    await dialog.screenshot({ path: testInfo.outputPath('recording.png') });
    await dialog.getByRole('button', { name: '停止' }).click();
    await expect(
        dialog.locator('[data-test="recording-preview"]'),
    ).toBeVisible();
    await dialog.screenshot({ path: testInfo.outputPath('recorded.png') });
    await dialog.getByRole('button', { name: '採用' }).click();
    await expect(dialog).toContainText('第 2 / 6 個');

    // 用鍵盤：空白鍵開始、停止，Enter 採用
    await dialog.locator('[data-test="sequential-word"]').click();
    await page.keyboard.press(' ');
    await expect(dialog.locator('[data-test="recording-clock"]')).toHaveText(
        /^0:01 /,
    );
    await page.keyboard.press(' ');
    await expect(
        dialog.locator('[data-test="recording-preview"]'),
    ).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(dialog).toContainText('第 3 / 6 個');

    // 略過其餘的詞
    for (let i = 3; i <= 6; i++) {
        await dialog.getByRole('button', { name: '略過這個詞' }).click();
    }
    await expect(dialog).toContainText('錄了 2 個詞，略過 4 個');
    await dialog.getByRole('button', { name: '關閉' }).first().click();
    await expect(playButtons).toHaveCount(2);

    // 只錄第三個詞
    await page.getByTitle('錄製發音').first().click();
    await expect(page.getByRole('dialog')).toContainText(
        await words.nth(2).inputValue(),
    );
    await page.getByRole('button', { name: '開始錄音' }).click();
    await expect(page.locator('[data-test="recording-clock"]')).toHaveText(
        /^0:01 /,
    );
    await page.getByRole('button', { name: '停止' }).click();
    await page.getByRole('button', { name: '採用' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(playButtons).toHaveCount(3);

    // 儲存後仍在
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    await expect(page.getByText('已儲存').first()).toBeVisible();
    await page.reload();
    await expect(playButtons).toHaveCount(3);
    await expect(playButtons.first()).toContainText('秒');

    expect(errors).toEqual([]);
});
