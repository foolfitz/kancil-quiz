import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { collectErrors, createActivity } from './helpers';

// 轉盤（docs/SPEC.md 7.5）：轉到哪個詞就顯示那張卡，點卡片翻面；轉到的詞從轉盤拿掉，轉完結束。
// 每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。

// 指針尖端正下方是哪一片。暫時藏起蓋在轉盤上的卡片，並讓轉盤接收點擊
// （轉盤平常不接收點擊，elementFromPoint 會略過它）
async function sliceUnderPointer(page: Page): Promise<string | null> {
    return page.evaluate(() => {
        const overlay =
            document.querySelector<HTMLElement>('.kq-wheel__overlay');
        const pointer = document.querySelector('.kq-wheel__pointer');
        const disc = document.querySelector<HTMLElement>('.kq-wheel__disc');
        if (!overlay || !pointer || !disc) {
            return null;
        }
        const box = pointer.getBoundingClientRect();
        overlay.style.visibility = 'hidden';
        disc.style.pointerEvents = 'auto';
        const hit = document.elementFromPoint(
            box.left + box.width / 2,
            box.bottom + 8,
        );
        overlay.style.visibility = '';
        disc.style.pointerEvents = '';
        return (
            hit?.closest('[data-entry-id]')?.getAttribute('data-entry-id') ??
            null
        );
    });
}

test('老師用教材的一課建立轉盤，轉到的詞與指針指的一致，轉完結束', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);

    // 已由 auth.setup.ts 以老師身分登入
    await page.goto('/curriculum/id/1/3');
    await page.locator('[data-test="lesson-activity"]').click();
    const playPath = await createActivity(page, /轉盤/);

    await page.context().clearCookies(); // 學生不登入
    await page.goto(playPath);
    await page.getByRole('button', { name: '開始' }).click();

    const slices = page.locator('.kq-wheel__slice');
    await expect(slices).toHaveCount(6);
    // 轉盤上顯示題目那一面的中文
    await expect(page.locator('.kq-wheel__svg')).toContainText('爸爸');
    // 整個轉盤在畫面內
    const wheel = await page.locator('.kq-wheel__wrap').boundingBox();
    const viewport = page.viewportSize();
    expect((wheel?.y ?? 0) + (wheel?.height ?? 0)).toBeLessThanOrEqual(
        viewport?.height ?? 0,
    );
    expect(wheel?.width ?? 0).toBeGreaterThan(300);
    await page.screenshot({ path: testInfo.outputPath('wheel.png') });

    // 第一次照常轉（有動畫），停下後的卡片就是指針指的那一片
    await page.getByRole('button', { name: '轉！' }).click();
    const overlay = page.locator('.kq-wheel__overlay');
    await expect(overlay).toBeVisible({ timeout: 8000 });
    const landed = await overlay.getAttribute('data-entry-id');
    expect(landed).not.toBeNull();
    expect(await sliceUnderPointer(page)).toBe(landed);
    await page.screenshot({ path: testInfo.outputPath('wheel-result.png') });

    // 點卡片翻面，看到印尼語。翻過去的那一面真的看不到（見圖卡牆的測試）
    await expect(page.locator('.kq-wheel__side--second')).toBeHidden();
    await page.locator('.kq-wheel__result').click();
    await expect(page.locator('.kq-wheel__inner')).toHaveClass(/is-flipped/);
    await expect(page.locator('.kq-wheel__side--first')).toBeHidden();
    await expect(page.locator('.kq-wheel__side--second')).toBeVisible();

    // 之後不播動畫，加快測試；轉到的詞拿掉，每個詞只轉到一次
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const seen = new Set([landed]);
    for (let remaining = 5; remaining >= 1; remaining--) {
        await page.getByRole('button', { name: /再轉一次|繼續/ }).click();
        await expect(slices).toHaveCount(remaining);
        await expect(overlay).toBeVisible();
        const id = await overlay.getAttribute('data-entry-id');
        expect(seen.has(id)).toBe(false);
        expect(await sliceUnderPointer(page)).toBe(id);
        seen.add(id);
    }
    await page.getByRole('button', { name: '繼續' }).click();
    await expect(page.getByText('全部轉完了！')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('wheel-empty.png') });

    // 重新開始：詞放回轉盤
    await page
        .locator('.kq-wheel__empty')
        .getByRole('button', { name: '重新開始' })
        .click();
    await expect(slices).toHaveCount(6);

    await page.getByRole('button', { name: '完成 ✓' }).click();
    await expect(
        page.getByRole('heading', { name: '看過 6 / 6 張' }),
    ).toBeVisible();
    await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);

    expect(errors).toEqual([]);
});
