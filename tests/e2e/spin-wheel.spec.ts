import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import {
    collectErrors,
    createActivity,
    expectInViewport,
    expectNothingClipped,
} from './helpers';

// 轉盤（docs/SPEC.md 7.5）：停下後，轉到的那一片亮著、其他變暗，接著跳出 modal 放大顯示那個詞
// （不翻面、不顯示中文意思與插圖），按「再轉一次」直接繼續。轉到的詞在下一次轉的時候拿掉，轉完結束。
// 每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。

// 指針尖端正下方是哪一片。暫時藏起蓋在轉盤上的 modal，並讓轉盤接收點擊
// （轉盤平常不接收點擊，elementFromPoint 會略過它）
async function sliceUnderPointer(page: Page): Promise<string | null> {
    return page.evaluate(() => {
        const modal = document.querySelector<HTMLElement>('.kq-wheel__modal');
        const pointer = document.querySelector('.kq-wheel__pointer');
        const disc = document.querySelector<HTMLElement>('.kq-wheel__disc');
        if (!modal || !pointer || !disc) {
            return null;
        }
        const box = pointer.getBoundingClientRect();
        modal.style.visibility = 'hidden';
        disc.style.pointerEvents = 'auto';
        const hit = document.elementFromPoint(
            box.left + box.width / 2,
            box.bottom + 8,
        );
        modal.style.visibility = '';
        disc.style.pointerEvents = '';
        return (
            hit?.closest('[data-entry-id]')?.getAttribute('data-entry-id') ??
            null
        );
    });
}

test('試玩轉盤可以在開始畫面選用課文的句子，轉盤上只有編號，轉到才放大顯示整句', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);
    await page.context().clearCookies(); // 訪客
    await page.goto('/curriculum/id/1/3/play/spin-wheel');

    // 開始畫面：預設用詞彙，可以改成句子；題組名稱與題數跟著換
    const words = page.getByRole('button', { name: '詞彙（6 個）' });
    const sentences = page.getByRole('button', { name: '句子（4 句）' });
    await expect(words).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByText('共 6 題')).toBeVisible();
    await sentences.click();
    await expect(sentences).toHaveAttribute('aria-pressed', 'true');
    await expect(sentences).toBeFocused();
    await expect(words).toHaveAttribute('aria-pressed', 'false');
    await expect(
        page.getByRole('heading', {
            name: '第 1 冊第 3 課：Keluarga Saya 我的家人（課文句子）',
        }),
    ).toBeVisible();
    await expect(page.getByText('共 4 題')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('sentences-start.png') });
    await page.getByRole('button', { name: '開始' }).click();

    // 扇形上只有編號，整句放大跳出來；不顯示中文意思
    const slices = page.locator('.kq-wheel__slice');
    await expect(slices).toHaveCount(4);
    await expect(page.locator('.kq-wheel__svg')).not.toContainText('sayang');
    await expect(page.locator('.kq-wheel__status')).toHaveText('轉盤上有 4 句');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.getByRole('button', { name: '轉！' }).click();
    const modal = page.getByRole('dialog', { name: '轉到的句子' });
    await expect(modal).toBeVisible();
    expect(await sliceUnderPointer(page)).toBe(
        await modal.getAttribute('data-entry-id'),
    );
    await expect(page.locator('.kq-wheel__word-text')).toContainText(
        /^Saya sayang \w+\.$/,
    );
    await expect(page.locator('.kq-wheel')).not.toContainText('我愛');
    await expectInViewport(page, page.locator('.kq-wheel__card'));
    await expectNothingClipped(page, '.kq-wheel__word');
    await page.screenshot({
        path: testInfo.outputPath('sentences-result.png'),
    });

    // 結果頁之後回到開始畫面，仍是句子
    await page.getByRole('button', { name: '完成 ✓' }).click();
    await expect(
        page.getByRole('heading', { name: '看過 1 / 4 張' }),
    ).toBeVisible();

    expect(errors).toEqual([]);
});

test('老師用教材的一課建立轉盤，轉到的詞放大跳出來，與指針指的一致，轉完結束', async ({
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
    // 轉盤上是目標語（題目那一面是插圖與中文意思，答案那一面才是印尼語），整個遊戲都不顯示中文意思
    await expect(page.locator('.kq-wheel__svg')).toContainText('ayah');
    await expect(page.locator('.kq-wheel')).not.toContainText('爸爸');
    // 整個轉盤在畫面內
    const wheel = await page.locator('.kq-wheel__wrap').boundingBox();
    const viewport = page.viewportSize();
    expect((wheel?.y ?? 0) + (wheel?.height ?? 0)).toBeLessThanOrEqual(
        viewport?.height ?? 0,
    );
    expect(wheel?.width ?? 0).toBeGreaterThan(300);
    await page.screenshot({ path: testInfo.outputPath('wheel.png') });

    // 第一次照常轉（有動畫）。停下後跳出的詞就是指針指的那一片，那一片亮著、其他變暗
    await page.getByRole('button', { name: '轉！' }).click();
    const modal = page.getByRole('dialog', { name: '轉到的詞' });
    await expect(modal).toBeVisible({ timeout: 8000 });
    const landed = await modal.getAttribute('data-entry-id');
    expect(landed).not.toBeNull();
    expect(await sliceUnderPointer(page)).toBe(landed);
    const landedSlice = page.locator('.kq-wheel__slice.is-landed');
    await expect(landedSlice).toHaveCount(1);
    await expect(landedSlice).toHaveAttribute('data-entry-id', landed ?? '');
    const word =
        (await page.locator('.kq-wheel__word-text').textContent()) ?? '';
    expect(word).not.toBe('');
    // 扇形上太長的詞以「…」截斷
    const label = (await landedSlice.textContent()) ?? '';
    expect(word.startsWith(label.replace(/…$/, ''))).toBe(true);
    await expect(page.locator('.kq-wheel')).not.toContainText('爸爸');
    await expectInViewport(page, page.locator('.kq-wheel__card'));
    await expectNothingClipped(page, '.kq-wheel__word');
    // 示範資料沒有音檔：沒有發音就不出現「聽發音」
    await expect(modal.getByRole('button', { name: '聽發音' })).toBeHidden();
    await page.screenshot({ path: testInfo.outputPath('wheel-result.png') });

    // 回到轉盤：轉到的那一片還亮著，詞還在轉盤上（下一次轉的時候才拿掉）
    await modal.getByRole('button', { name: '回到轉盤' }).click();
    await expect(modal).toBeHidden();
    await expect(landedSlice).toHaveAttribute('data-entry-id', landed ?? '');
    await expect(slices).toHaveCount(6);

    // 之後不播動畫，加快測試；按中央的「轉！」或 modal 的「再轉一次」，上一個詞拿掉，每個詞只轉到一次
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.getByRole('button', { name: '轉！' }).click();
    const seen = new Set([landed]);
    for (let remaining = 5; remaining >= 1; remaining--) {
        await expect(slices).toHaveCount(remaining);
        await expect(modal).toBeVisible();
        const id = await modal.getAttribute('data-entry-id');
        expect(seen.has(id)).toBe(false);
        expect(await sliceUnderPointer(page)).toBe(id);
        seen.add(id);
        if (remaining > 1) {
            await modal.getByRole('button', { name: '再轉一次' }).click();
        }
    }
    await modal.getByRole('button', { name: '繼續' }).click();
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
