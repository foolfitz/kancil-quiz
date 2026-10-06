import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import {
    collectErrors,
    expectGameFits,
    expectInViewport,
    expectNoHorizontalOverflow,
    expectNothingClipped,
    touchDragTo,
    whackAnswer,
    whackPrompt,
    archerPrompt,
    archerToLandscape,
    shootBalloon,
} from './helpers';

// 手機（docs/SPEC.md S-02、7.6）：直向與橫放的手機都能玩完每個遊戲，一頁的內容都在畫面內、不必捲動，
// 也沒有水平捲軸。用不必登入的教材試玩頁（S-06），資料來自 DemoSeeder。
// 只在 *-phone 與 *-phone-landscape 的 project 執行（playwright.config.ts 的 testMatch）。

// 教材試玩固定在左上角的「回到這一課」不能蓋到標題
async function expectBackLinkClearOfTitle(page: Page): Promise<void> {
    const back = await page.locator('.kq-trial-back').boundingBox();
    const title = await page.locator('.kq-player__title').boundingBox();
    expect(back).not.toBeNull();
    expect(title).not.toBeNull();
    expect(title?.y ?? 0).toBeGreaterThanOrEqual(
        (back?.y ?? 0) + (back?.height ?? 0),
    );
}

async function start(page: Page, path: string): Promise<void> {
    await page.goto(path);
    const button = page.getByRole('button', { name: '開始' });
    await expectInViewport(page, button);
    await expectBackLinkClearOfTitle(page);
    await button.click();
}

test('配對：一頁的題目與卡片都在畫面內，手指拖曳不會捲動', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);
    await start(page, '/curriculum/id/1/3/play/match-up');

    // 6 個詞、每頁最多 6 組（7.5）：直向的題目一列一列往下排，放得下 6 組；橫放的題目排成一排、
    // 圖片在文字左邊、卡片在下面，每一格比較寬，分成 3、3 兩頁。每一頁的題目與卡片都要在畫面內
    const progress = page.locator('.kq-match__progress');
    await expect(progress).toHaveText(
        isLandscape(page) ? '第 1 / 2 頁｜配好 0 / 3 組' : '配好 0 / 6 組',
    );
    await expectMatchLayout(page);
    await expectMatchPageFits(page);
    await expectNothingClipped(page, '.kq-match__card, .kq-match__prompt');
    await page.screenshot({ path: testInfo.outputPath('match-phone.png') });

    // 手指把第一張卡片拖到對應的題目：配對成功，畫面沒有跟著捲動
    const first = page.locator('.kq-match__row').first();
    const firstId = await first.getAttribute('data-entry-id');
    const scrollBefore = await page.evaluate(() => window.scrollY);
    await touchDragTo(
        page,
        page.locator(`.kq-match__pool [data-entry-id="${firstId}"]`),
        first,
    );
    await expect(first).toHaveClass(/is-matched/);
    expect(await page.evaluate(() => window.scrollY)).toBe(scrollBefore);

    // 其餘的用點選配完每一頁；配好的卡片放進格子也不會把整頁撐高
    await finishMatch(page);
    await expectBackLinkClearOfTitle(page);
    await expectNoHorizontalOverflow(page);

    expect(errors).toEqual([]);
});

function isLandscape(page: Page): boolean {
    const viewport = page.viewportSize();
    return (viewport?.width ?? 0) > (viewport?.height ?? 0);
}

// 直向的題目一列一列往下排；橫放（矮而寬）的題目排成一排、圖片在文字左邊，卡片都在題目下面（7.5）
async function expectMatchLayout(page: Page): Promise<void> {
    const landscape = isLandscape(page);
    const rows = await page
        .locator('.kq-match__row')
        .evaluateAll((elements) =>
            elements.map((el) => el.getBoundingClientRect()),
        );
    expect(rows.length).toBeGreaterThan(1);
    if (landscape) {
        for (const row of rows) {
            expect(Math.abs(row.top - rows[0].top)).toBeLessThanOrEqual(1);
        }
        const bottom = Math.max(...rows.map((row) => row.bottom));
        for (const card of await page
            .locator('.kq-match__pool .kq-match__card')
            .all()) {
            expect((await card.boundingBox())?.y ?? 0).toBeGreaterThanOrEqual(
                bottom - 1,
            );
        }
        for (const prompt of await page
            .locator('.kq-match__row .kq-match__prompt')
            .all()) {
            const image = prompt.locator('.kq-match__image');
            if ((await image.count()) === 0) {
                continue;
            }
            const imageBox = await image.boundingBox();
            const textBox = await prompt
                .locator('.kq-match__text')
                .boundingBox();
            expect(
                (imageBox?.x ?? 0) + (imageBox?.width ?? 0),
            ).toBeLessThanOrEqual((textBox?.x ?? 0) + 1);
        }
    } else {
        for (let i = 1; i < rows.length; i++) {
            expect(rows[i].top).toBeGreaterThanOrEqual(rows[i - 1].bottom - 1);
        }
    }
}

// 每一頁的題目與卡片都在畫面內，也沒有水平捲軸
async function expectMatchPageFits(page: Page): Promise<void> {
    await expectGameFits(page);
    await expectNoHorizontalOverflow(page);
    for (const element of await page
        .locator('.kq-match__row, .kq-match__card')
        .all()) {
        await expectInViewport(page, element);
    }
}

// 用點選把剩下的每一頁配完，6 個詞都答對
async function finishMatch(page: Page): Promise<void> {
    const progress = page.locator('.kq-match__progress');
    const pages = Number(
        (await progress.textContent())?.match(/\/ (\d+) 頁/)?.[1] ?? 1,
    );
    const current = Number(
        (await progress.textContent())?.match(/^第 (\d+) \//)?.[1] ?? 1,
    );
    for (let i = current; i <= pages; i++) {
        const ids = await page
            .locator('.kq-match__row:not(.is-matched)')
            .evaluateAll((elements) =>
                elements.map((el) => (el as HTMLElement).dataset.entryId ?? ''),
            );
        for (const id of ids) {
            await matchByTap(page, id);
            await expectGameFits(page);
        }
        if (i < pages) {
            await expect(progress).toHaveText(
                new RegExp(`^第 ${i + 1} / ${pages} 頁`),
            );
            await expectMatchLayout(page);
            await expectMatchPageFits(page);
        }
    }
    await expect(
        page.getByRole('heading', { name: '答對 6 / 6 題' }),
    ).toBeVisible();
}

// 用點選配好一題
async function matchByTap(page: Page, id: string): Promise<void> {
    await page.locator(`.kq-match__pool [data-entry-id="${id}"]`).click();
    await page
        .locator(`.kq-match__row[data-entry-id="${id}"] .kq-match__drop`)
        .click();
    await expect(
        page.locator(`.kq-match__row[data-entry-id="${id}"]`),
    ).toHaveClass(/is-matched/);
}

test('配對：玩到一半轉向，依新的畫面重新分頁，配好的照舊', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);
    await start(page, '/curriculum/id/1/3/play/match-up');

    // 先配好第一組
    const viewport = page.viewportSize();
    if (!viewport) {
        throw new Error('沒有 viewport');
    }
    const startLandscape = isLandscape(page);
    const progress = page.locator('.kq-match__progress');
    await expect(progress).toHaveText(
        startLandscape ? '第 1 / 2 頁｜配好 0 / 3 組' : '配好 0 / 6 組',
    );
    const first = page.locator('.kq-match__row').first();
    const firstId = (await first.getAttribute('data-entry-id')) ?? '';
    await touchDragTo(
        page,
        page.locator(`.kq-match__pool [data-entry-id="${firstId}"]`),
        first,
    );
    await expect(first).toHaveClass(/is-matched/);

    // 轉成另一個方向（7.5）：直向一頁放得下 6 組，橫放的每一格比較寬，分成 3、3 兩頁。
    // 配好的那一組留在這一頁、仍然是配好的，卡片區看不到它的卡片；一頁的內容都在畫面內
    await page.setViewportSize({
        width: viewport.height,
        height: viewport.width,
    });
    await expect(progress).toHaveText(
        startLandscape ? '配好 1 / 6 組' : '第 1 / 2 頁｜配好 1 / 3 組',
    );
    const matched = page.locator(`.kq-match__row[data-entry-id="${firstId}"]`);
    await expect(matched).toHaveClass(/is-matched/);
    await expect(matched.locator('.kq-match__card.is-placed')).toBeVisible();
    await expect(
        page.locator(`.kq-match__pool [data-entry-id="${firstId}"]`),
    ).toBeHidden();
    await expectMatchLayout(page);
    await expectMatchPageFits(page);
    await page.screenshot({ path: testInfo.outputPath('match-rotated.png') });

    // 再配一組，轉回來：這一頁配好的兩組都留著
    const secondId = await page
        .locator('.kq-match__row:not(.is-matched)')
        .first()
        .evaluate((el) => (el as HTMLElement).dataset.entryId ?? '');
    await matchByTap(page, secondId);
    await page.setViewportSize(viewport);
    await expect(progress).toHaveText(
        startLandscape ? '第 1 / 2 頁｜配好 2 / 3 組' : '配好 2 / 6 組',
    );
    await expect(page.locator('.kq-match__row.is-matched')).toHaveCount(2);
    await expectMatchLayout(page);
    await expectMatchPageFits(page);

    // 把剩下的配完：配好的只算一次，成績是 6 / 6
    await finishMatch(page);
    await expectNoHorizontalOverflow(page);

    expect(errors).toEqual([]);
});

test('選擇題：每一題的選項都在畫面內，玩完看到結果', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);
    await start(page, '/curriculum/id/1/2/play/quiz');

    const progress = page.locator('.kq-quiz__progress');
    for (let i = 1; i <= 7; i++) {
        await expect(progress).toHaveText(`第 ${i} / 7 題`);
        await expectGameFits(page);
        await expectNoHorizontalOverflow(page);
        for (const option of await page.locator('.kq-quiz__option').all()) {
            await expectInViewport(page, option);
        }
        await expectNothingClipped(page, '.kq-quiz__option');
        if (i === 1) {
            await page.screenshot({
                path: testInfo.outputPath('quiz-phone.png'),
            });
        }
        await page.locator('.kq-quiz__option').first().click();
        await expect(page.locator('.kq-quiz__feedback')).toHaveText(
            /答對了|答錯了/,
        );
    }

    await expect(
        page.getByRole('heading', { name: /^答對 \d \/ 7 題$/ }),
    ).toBeVisible();
    await expectBackLinkClearOfTitle(page);
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: testInfo.outputPath('results-phone.png') });

    expect(errors).toEqual([]);
});

test('打地鼠：題目與九個洞都在畫面內，時間到看到結果', async ({
    page,
}, testInfo) => {
    const errors = collectErrors(page);
    await page.clock.install();
    await start(page, '/curriculum/id/1/3/play/whack-a-mole');

    // 直向的題目在上、草地在下；橫放的題目在左邊一欄、草地在右邊（7.5）
    const prompt = page.locator('.kq-whack__prompt');
    const field = page.locator('.kq-whack__field');
    await expectInViewport(page, prompt);
    await expectInViewport(page, field);
    await expectInViewport(page, page.locator('.kq-whack__timer'));
    const promptBox = await prompt.boundingBox();
    const fieldBox = await field.boundingBox();
    if (isLandscape(page)) {
        expect(
            (promptBox?.x ?? 0) + (promptBox?.width ?? 0),
        ).toBeLessThanOrEqual(fieldBox?.x ?? 0);
    } else {
        expect(
            (promptBox?.y ?? 0) + (promptBox?.height ?? 0),
        ).toBeLessThanOrEqual(fieldBox?.y ?? 0);
    }
    expect(fieldBox?.height).toBeGreaterThan(200);
    for (const hole of await page.locator('.kq-whack__hole').all()) {
        await expectInViewport(page, hole);
    }
    await expectGameFits(page);
    await expectNoHorizontalOverflow(page);

    // 打對三題；地鼠舉著的字不被截切
    let entryId: string | null = null;
    for (let i = 0; i < 3; i++) {
        entryId = await whackPrompt(page, entryId);
        if (i === 0) {
            await expect(
                page.locator(
                    `.kq-whack__hole.is-up[data-option-id="${entryId}"]`,
                ),
            ).toBeVisible({ timeout: 15_000 });
            await expectNothingClipped(page, '.kq-whack__sign');
            await page.screenshot({
                path: testInfo.outputPath('whack-phone.png'),
            });
        }
        await whackAnswer(page, entryId);
    }
    await expect(page.locator('.kq-whack__stars')).toHaveText('★ 6');

    // 時間到：第一輪沒輪到的三題算沒有作答
    await page.clock.fastForward('01:00');
    await expect(
        page.getByRole('heading', { name: '答對 3 / 6 題' }),
    ).toBeVisible();
    await expect(page.locator('.kq-player__score')).toHaveText('星星 6');
    await expect(page.getByText('沒有作答')).toHaveCount(3);
    await expectBackLinkClearOfTitle(page);
    await expectNoHorizontalOverflow(page);

    expect(errors).toEqual([]);
});

test('射氣球：直向時請學生轉成橫的，橫放時天空與弓箭手都在畫面內', async ({
    page,
}, testInfo) => {
    test.setTimeout(90_000);
    const errors = collectErrors(page);
    await page.clock.install();
    await start(page, '/curriculum/id/1/3/play/balloon-archer');
    await archerToLandscape(page);

    // 橫放：題目在左邊一欄，天空、柱子與弓箭手在右邊
    const prompt = page.locator('.kq-archer__prompt');
    const field = page.locator('.kq-archer__field');
    await expectInViewport(page, prompt);
    await expectInViewport(page, field);
    await expectInViewport(page, page.locator('.kq-archer__player'));
    const promptBox = await prompt.boundingBox();
    const fieldBox = await field.boundingBox();
    expect((promptBox?.x ?? 0) + (promptBox?.width ?? 0)).toBeLessThanOrEqual(
        fieldBox?.x ?? 0,
    );
    await expectGameFits(page);
    await expectNoHorizontalOverflow(page);
    await expect(
        page.locator('.kq-archer__balloon.is-flying').first(),
    ).toBeAttached();
    await page.clock.runFor(2500);
    await page.screenshot({ path: testInfo.outputPath('archer-phone.png') });

    // 手指拉弓射中三題
    let entryId: string | null = null;
    for (let i = 0; i < 3; i++) {
        entryId = await archerPrompt(page, entryId);
        await shootBalloon(page, entryId, { touch: true });
    }
    await expect(page.locator('.kq-archer__stars')).toHaveText('★ 6');

    // 時間到：第一輪沒輪到的三題算沒有作答
    await page.clock.fastForward('01:00');
    await expect(
        page.getByRole('heading', { name: '答對 3 / 6 題' }),
    ).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('.kq-player__score')).toHaveText('星星 6');
    await expect(page.getByText('沒有作答')).toHaveCount(3);
    await expectNoHorizontalOverflow(page);

    expect(errors).toEqual([]);
});

test('字卡：卡片與按鈕都在畫面內，可以翻面、換卡', async ({ page }) => {
    const errors = collectErrors(page);
    await start(page, '/curriculum/id/1/3/play/flash-cards');

    await expect(page.locator('.kq-cards__progress')).toHaveText('第 1 / 6 張');
    await expectGameFits(page);
    await expectNoHorizontalOverflow(page);
    await expectInViewport(page, page.locator('.kq-cards__card'));
    await expectInViewport(page, page.getByRole('button', { name: '翻面' }));
    await expectInViewport(
        page,
        page.getByRole('button', { name: '下一張 →' }),
    );

    await page.locator('.kq-cards__card').click();
    await expect(page.locator('.kq-cards__inner')).toHaveClass(/is-flipped/);
    await expect(page.locator('.kq-cards__side--first')).toBeHidden();
    await expectNothingClipped(page, '.kq-cards__text');
    await page.getByRole('button', { name: '下一張 →' }).click();
    await expect(page.locator('.kq-cards__progress')).toHaveText('第 2 / 6 張');
    await expectGameFits(page);

    expect(errors).toEqual([]);
});

test('圖卡牆：一課的卡片一頁放得下，按鈕在畫面內', async ({ page }) => {
    const errors = collectErrors(page);
    await start(page, '/curriculum/id/1/3/play/card-wall');

    const cards = page.locator('.kq-wall__card');
    await expect(cards).toHaveCount(6);
    await expect(page.locator('.kq-wall__grid')).not.toHaveClass(
        /is-scrolling/,
    );
    await expectNoHorizontalOverflow(page);
    for (const card of await cards.all()) {
        await expectInViewport(page, card);
    }
    const flipAll = page.getByRole('button', { name: '全部翻面' });
    await expectInViewport(page, flipAll);
    await flipAll.click();
    await expect(page.locator('.kq-wall__inner.is-flipped')).toHaveCount(6);
    await page.waitForTimeout(600); // 等翻面動畫結束
    await expectNothingClipped(page, '.kq-wall__side--second .kq-wall__text');

    expect(errors).toEqual([]);
});

test('轉盤：整個轉盤在畫面內，轉動時沒有水平捲軸', async ({ page }) => {
    const errors = collectErrors(page);
    await start(page, '/curriculum/id/1/3/play/spin-wheel');

    await expect(page.locator('.kq-wheel__slice')).toHaveCount(6);
    await expectInViewport(page, page.locator('.kq-wheel__wrap'));
    await expectInViewport(page, page.getByRole('button', { name: '完成 ✓' }));
    await expectNoHorizontalOverflow(page);

    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.getByRole('button', { name: '轉！' }).click();
    const overlay = page.locator('.kq-wheel__overlay');
    await expect(overlay).toBeVisible();
    // 轉過的方形轉盤四角超出畫面，不可以把頁面撐出水平捲軸
    await expectNoHorizontalOverflow(page);
    await expectInViewport(page, page.locator('.kq-wheel__result'));
    await expectInViewport(
        page,
        page.getByRole('button', { name: '再轉一次' }),
    );

    expect(errors).toEqual([]);
});

test('迷宮問答：迷宮、題目與暫停鍵都在畫面內', async ({ page }, testInfo) => {
    const errors = collectErrors(page);
    await start(page, '/curriculum/id/1/3/play/maze-quiz');

    const canvas = page.locator('.kq-maze canvas').first();
    await expect(canvas).toBeVisible();
    await expectInViewport(page, canvas);
    const box = await canvas.boundingBox();
    expect(box?.width).toBeGreaterThan(300);
    expect(box?.height).toBeGreaterThan(180);
    await expectInViewport(page, page.locator('.kq-maze-prompt-text'));
    await expectInViewport(page, page.getByRole('button', { name: '暫停' }));
    await expectNoHorizontalOverflow(page);
    await page.waitForTimeout(1500);
    await page.screenshot({ path: testInfo.outputPath('maze-phone.png') });

    await page.getByRole('button', { name: '暫停' }).click();
    await expect(page.locator('.kq-maze')).toContainText('繼續');
    await expectInViewport(page, page.getByRole('button', { name: '繼續' }));

    expect(errors).toEqual([]);
});
