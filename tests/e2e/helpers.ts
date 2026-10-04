import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

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
