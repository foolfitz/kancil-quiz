import { expect, test } from '@playwright/test';
import { collectErrors, createActivity, expectNothingClipped } from './helpers';

// 圖卡牆（docs/SPEC.md 7.5）：一頁排出所有卡片，點一下翻面，可以全部翻面；結束後顯示看過的張數。
// 每個 project（iPad 直向、橫向、投影尺寸）各跑一次，資料來自 DemoSeeder。

test('選遊戲的畫面分成遊戲與互動教材；圖卡牆一頁放得下一課的卡片', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);

    // 已由 auth.setup.ts 以老師身分登入
    await page.goto('/curriculum/id/1/3');
    await page.locator('[data-test="lesson-activity"]').click();

    const games = page.locator('[data-test="game-group-game"]');
    const materials = page.locator('[data-test="game-group-material"]');
    await expect(games.getByRole('heading', { name: '遊戲' })).toBeVisible();
    await expect(games).toContainText('選擇題');
    await expect(
        materials.getByRole('heading', { name: '互動教材' }),
    ).toBeVisible();
    for (const name of ['字卡', '圖卡牆', '轉盤']) {
        await expect(materials).toContainText(name);
        await expect(games).not.toContainText(name);
    }
    await page.screenshot({
        path: testInfo.outputPath('game-groups.png'),
        fullPage: true,
    });

    const playPath = await createActivity(page, /圖卡牆/);

    await page.context().clearCookies(); // 學生不登入
    await page.goto(playPath);
    await page.getByRole('button', { name: '開始' }).click();

    // 6 張卡都在畫面內，不必捲動
    const cards = page.locator('.kq-wall__card');
    await expect(cards).toHaveCount(6);
    await expect(page.locator('.kq-wall__grid')).not.toHaveClass(
        /is-scrolling/,
    );
    const viewport = page.viewportSize();
    for (const card of await cards.all()) {
        const box = await card.boundingBox();
        expect(box).not.toBeNull();
        expect((box?.y ?? 0) + (box?.height ?? 0)).toBeLessThanOrEqual(
            viewport?.height ?? 0,
        );
        // 卡片夠大，投影時後排看得到
        expect(box?.width ?? 0).toBeGreaterThan(140);
    }
    await expect
        .poll(() =>
            page
                .locator('.kq-wall__side--first img')
                .first()
                .evaluate((img: HTMLImageElement) => img.naturalWidth),
        )
        .toBeGreaterThan(0);
    await expect(page.locator('.kq-wall__progress')).toBeHidden();
    await page.screenshot({ path: testInfo.outputPath('wall.png') });

    // 背面真的看不到（有的 WebKit 不支援 backface-visibility，背面會鏡像蓋在正面上）
    await expect(page.locator('.kq-wall__side--second').first()).toBeHidden();

    // 點一張卡翻面，看到印尼語（卡片順序每次不同）
    await cards.first().click();
    await expect(page.locator('.kq-wall__side--first').first()).toBeHidden();
    await expect(page.locator('.kq-wall__inner').first()).toHaveClass(
        /is-flipped/,
    );
    await expect(page.locator('.kq-wall__side--second').first()).toHaveText(
        /^[a-z ]+$/,
    );

    // 全部翻面，再全部翻回來
    await page.getByRole('button', { name: '全部翻面' }).click();
    await expect(page.locator('.kq-wall__inner.is-flipped')).toHaveCount(6);
    await page.waitForTimeout(600); // 等翻面動畫結束再截圖
    await expectNothingClipped(page, '.kq-wall__side--second .kq-wall__text');
    await page.screenshot({ path: testInfo.outputPath('wall-flipped.png') });
    await page.getByRole('button', { name: '全部翻回來' }).click();
    await expect(page.locator('.kq-wall__inner.is-flipped')).toHaveCount(0);

    await page.getByRole('button', { name: '完成 ✓' }).click();
    await expect(
        page.getByRole('heading', { name: '看過 6 / 6 張' }),
    ).toBeVisible();
    await expect(page.getByText('成績沒有上傳成功')).toHaveCount(0);

    expect(errors).toEqual([]);
});
