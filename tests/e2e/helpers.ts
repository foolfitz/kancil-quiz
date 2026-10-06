import { expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

// 各遊戲的 E2E 共用的檢查。

export function collectErrors(page: Page): string[] {
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
export async function expectNothingClipped(
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

// 在老師端的建立活動頁選遊戲、調整設定、建立活動，回傳學生端的播放路徑。
export async function createActivity(
    page: Page,
    game: RegExp,
    configure?: () => Promise<void>,
): Promise<string> {
    await page.getByRole('button', { name: game }).click();
    await configure?.();
    await page.getByRole('button', { name: '建立活動' }).click();
    await page.waitForURL('**/activities/*');
    const url = await page.locator('code').first().textContent();
    return new URL(url ?? '').pathname;
}

// 手機用：整頁沒有水平捲軸
export async function expectNoHorizontalOverflow(page: Page): Promise<void> {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

// 手機用：遊戲這一頁的內容都在遊戲區內，不必捲動（遊戲的根元素是 .kq-player__game-area 的第一個子元素）
export async function expectGameFits(page: Page): Promise<void> {
    const overflow = await page
        .locator('.kq-player__game-area > *')
        .first()
        .evaluate((root) => root.scrollHeight - root.clientHeight);
    expect(overflow).toBeLessThanOrEqual(1);
}

// 元素整個在畫面內（沒有被切到畫面外）
export async function expectInViewport(
    page: Page,
    locator: Locator,
): Promise<void> {
    const viewport = page.viewportSize();
    const box = await locator.boundingBox();
    expect(box, '找不到元素的位置').not.toBeNull();
    if (!box || !viewport) {
        return;
    }
    expect(box.x).toBeGreaterThanOrEqual(-1);
    expect(box.y).toBeGreaterThanOrEqual(-1);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1);
}

// 配對（match-up）用：以手指拖曳。Chromium 透過 CDP 送出真正的觸控事件（pointerType 是 touch，
// 會經過 touch-action 的判斷，和手機上一樣）；WebKit 沒有這個介面，退回滑鼠
export async function touchDragTo(
    page: Page,
    from: Locator,
    to: Locator,
): Promise<void> {
    if (page.context().browser()?.browserType().name() !== 'chromium') {
        return dragTo(page, from, to);
    }
    const a = await from.boundingBox();
    const b = await to.boundingBox();
    if (!a || !b) {
        throw new Error('找不到要拖曳的位置');
    }
    const start = { x: a.x + a.width / 2, y: a.y + a.height / 2 };
    const end = { x: b.x + b.width / 2, y: b.y + b.height / 2 };
    const cdp = await page.context().newCDPSession(page);
    try {
        await cdp.send('Input.dispatchTouchEvent', {
            type: 'touchStart',
            touchPoints: [start],
        });
        const steps = 12;
        for (let i = 1; i <= steps; i++) {
            await cdp.send('Input.dispatchTouchEvent', {
                type: 'touchMove',
                touchPoints: [
                    {
                        x: start.x + ((end.x - start.x) * i) / steps,
                        y: start.y + ((end.y - start.y) * i) / steps,
                    },
                ],
            });
        }
        await cdp.send('Input.dispatchTouchEvent', {
            type: 'touchEnd',
            touchPoints: [],
        });
    } finally {
        await cdp.detach();
    }
}

// 配對（match-up）用：以滑鼠拖曳
export async function dragTo(
    page: Page,
    from: Locator,
    to: Locator,
): Promise<void> {
    const a = await from.boundingBox();
    const b = await to.boundingBox();
    if (!a || !b) {
        throw new Error('找不到要拖曳的位置');
    }
    await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
    await page.mouse.down();
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, {
        steps: 10,
    });
    await page.mouse.up();
}

// 配好這一頁：雙數題用拖曳，單數題用點選（先點卡片，再點題目旁的空格）
export async function matchPage(page: Page): Promise<void> {
    const rows = page.locator('.kq-match__row');
    const ids = await rows.evaluateAll((elements) =>
        elements.map((el) => (el as HTMLElement).dataset.entryId ?? ''),
    );
    for (const [i, id] of ids.entries()) {
        const card = page.locator(`.kq-match__pool [data-entry-id="${id}"]`);
        const row = page.locator(`.kq-match__row[data-entry-id="${id}"]`);
        if (i % 2 === 0) {
            await dragTo(page, card, row);
        } else {
            await card.click();
            await row.locator('.kq-match__drop').click();
        }
        await expect(row).toHaveClass(/is-matched/);
    }
}

// 打地鼠：等這一題的題目出現（打對之後停一下才換題），回傳詞條 ID。詞彙組的正解選項 ID 就是詞條 ID
export async function whackPrompt(
    page: Page,
    previous: string | null,
): Promise<string> {
    const prompt = page.locator('.kq-whack__prompt');
    if (previous) {
        await expect(prompt).not.toHaveAttribute('data-entry-id', previous);
    }
    return (await prompt.getAttribute('data-entry-id')) ?? '';
}

// 打地鼠：等舉著正解的地鼠冒出來再打。正解最慢隔一批就會出現，一批最多停幾秒
export async function whackAnswer(page: Page, entryId: string): Promise<void> {
    const mole = page.locator(
        `.kq-whack__hole.is-up[data-option-id="${entryId}"]`,
    );
    await expect(mole).toBeVisible({ timeout: 15_000 });
    await mole.click();
}
