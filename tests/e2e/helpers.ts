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
