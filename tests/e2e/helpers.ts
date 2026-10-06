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
    await touchDrag(
        page,
        { x: a.x + a.width / 2, y: a.y + a.height / 2 },
        { x: b.x + b.width / 2, y: b.y + b.height / 2 },
    );
}

// 手指從 start 拖到 end（只有 Chromium，見 touchDragTo()）
async function touchDrag(
    page: Page,
    start: { x: number; y: number },
    end: { x: number; y: number },
): Promise<void> {
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

// 射氣球：射向帶著這個選項的氣球。需要先 page.clock.install()。
// 弓箭手在左邊，按住的高度就是弓箭手的高度，往左拉再放開，箭水平往右飛。
// 等氣球飄到地面上，把時間停住，量好位置、算出要瞄在氣球上面多少（箭飛過去時氣球往上飄了一段）。
// 箭會射中路上的第一個氣球，弓箭手也只能站在柱子的範圍內，所以路上有別的氣球擋住、或站不到那個高度時，
// 讓時間走一點再瞄。放開後快轉讓箭飛過去。touch 時 Chromium 用手指拉
export async function shootBalloon(
    page: Page,
    target: string | { not: string },
    { touch = false }: { touch?: boolean } = {},
): Promise<void> {
    // 射帶著這個選項的氣球；{ not } 是射任何一個不是這個選項的氣球（射錯的）
    const wanted = (id: string) =>
        typeof target === 'string' ? id === target : id !== target.not;
    const selector =
        typeof target === 'string'
            ? `.kq-archer__balloon.is-flying[data-option-id="${target}"]`
            : `.kq-archer__balloon.is-flying:not([data-option-id="${target.not}"])`;
    const ground = page.locator('.kq-archer__ground');
    await expect
        .poll(
            async () => {
                const box = await page.locator(selector).first().boundingBox();
                const top = (await ground.boundingBox())?.y ?? 0;
                return box !== null && box.y + box.height < top - 4;
            },
            { timeout: 20_000 },
        )
        .toBe(true);

    // 留一點時間差：WebKit 比較慢，太近的話停住的時間點已經過去了
    const now = await page.evaluate(() => Date.now());
    await page.clock.pauseAt(now + 300);
    const speed = Number(
        await page.locator('.kq-archer').getAttribute('data-arrow-speed'),
    );
    const field = await page.locator('.kq-archer__field').boundingBox();
    if (!field) {
        throw new Error('找不到遊戲區');
    }
    const bowCenter = async () => {
        const box = await page.locator('.kq-archer__bow').boundingBox();
        return box
            ? { x: box.x + box.width / 2, y: box.y + box.height / 2 }
            : { x: 0, y: 0 };
    };
    const bowX = (await bowCenter()).x;
    const startX = field.x + field.width * 0.6;

    for (let attempt = 0; attempt < 80; attempt++) {
        const balloons = await page
            .locator('.kq-archer__balloon.is-flying')
            .evaluateAll((elements) =>
                elements.map((el) => {
                    const box = el.getBoundingClientRect();
                    return {
                        id: (el as HTMLElement).dataset.optionId ?? '',
                        vy: Number((el as HTMLElement).dataset.vy),
                        cx: box.left + box.width / 2,
                        cy: box.top + box.height / 2,
                        rx: box.width / 2,
                        ry: box.height / 2,
                    };
                }),
            );
        for (const aim of balloons.filter((b) => wanted(b.id))) {
            // 箭頭飛到氣球的時候，氣球往上飄了 vy × t
            const t = (aim.cx - bowX) / speed;
            const y = aim.cy - aim.vy * t;
            // 路上（比目標近）的氣球在箭經過時擋不擋到，留一點餘裕
            const blocked = balloons.some((b) => {
                if (b === aim || b.cx > aim.cx) {
                    return false;
                }
                const cy = b.cy - b.vy * ((b.cx - bowX) / speed);
                return Math.abs(cy - y) < b.ry + 12;
            });
            if (!blocked) {
                await page.mouse.move(startX, y);
                await page.mouse.down();
                // 弓箭手站得到這個高度嗎（柱子的範圍）
                const reached = Math.abs((await bowCenter()).y - y) < 2;
                if (reached) {
                    const end = { x: startX - 60, y };
                    if (
                        touch &&
                        page.context().browser()?.browserType().name() ===
                            'chromium'
                    ) {
                        // 手指從頭再拉一次：先放開滑鼠（沒有往左拉，不會射）
                        await page.mouse.up();
                        await touchDrag(page, { x: startX, y }, end);
                    } else {
                        await page.mouse.move(end.x, end.y, { steps: 5 });
                        await page.mouse.up();
                    }
                    await page.clock.runFor(1000);
                    await page.clock.resume();
                    return;
                }
                // 站不到：沒有往左拉就放開，不會射
                await page.mouse.up();
            }
        }
        await page.clock.runFor(150);
    }
    throw new Error(
        `射不到 ${JSON.stringify(target)}：一直被別的氣球擋住，或是站不到那個高度`,
    );
}

// 射氣球：等這一題的題目出現（射中正解之後停一下才換題），回傳詞條 ID。詞彙組的正解選項 ID 就是詞條 ID
export async function archerPrompt(
    page: Page,
    previous: string | null,
): Promise<string> {
    const prompt = page.locator('.kq-archer__prompt');
    if (previous) {
        await expect(prompt).not.toHaveAttribute('data-entry-id', previous);
    }
    return (await prompt.getAttribute('data-entry-id')) ?? '';
}

// 射氣球：固定橫式版面。直向的畫面先蓋著「請轉成橫的」、倒數不動，轉成橫的才開始
export async function archerToLandscape(page: Page): Promise<void> {
    const viewport = page.viewportSize();
    if (!viewport || viewport.width > viewport.height) {
        await expect(page.locator('.kq-archer__rotate')).toBeHidden();
        return;
    }
    await expect(page.locator('.kq-archer__rotate')).toBeVisible();
    await expect(page.locator('.kq-archer__rotate')).toContainText(
        '請把手機或平板轉成橫的',
    );
    const clock = await page.locator('.kq-archer__timer').textContent();
    await page.clock.runFor(3000);
    await expect(page.locator('.kq-archer__timer')).toHaveText(clock ?? '');
    await page.setViewportSize({
        width: viewport.height,
        height: viewport.width,
    });
    await expect(page.locator('.kq-archer__rotate')).toBeHidden();
}
