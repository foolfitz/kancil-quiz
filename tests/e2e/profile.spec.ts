import { expect, test } from '@playwright/test';
import { wavFile } from './files';
import { collectErrors, expectNoHorizontalOverflow } from './helpers';

// 創作者資料與創作者頁面（docs/SPEC.md T-20）。示範老師填創作者資料後，新題組的授權與上傳媒體的
// 署名跟著變；從共備庫點同事的名字進到創作者頁面。每個 project 都會跑一次，最後把資料改回來，
// 其他 spec（editor.spec.ts 的署名）才不受影響。

const NAME = '示範老師（示範國小）';

test('老師填創作者資料後，署名與預設授權自動帶入；從共備庫看同事的創作者頁面', async ({
    page,
}, testInfo) => {
    test.slow(); // 上傳的音檔要等伺服器轉檔
    const errors = collectErrors(page);

    // 設定頁的「創作者資料」
    await page.goto('/settings/creator');
    await page.getByLabel('署名名稱').fill(NAME);
    await page.getByLabel('網址（選填）').fill('https://example.org/teacher');
    await page.getByLabel('預設授權').selectOption('CC-BY-SA-4.0');
    await page.getByLabel('學校（選填）').fill('示範國小');
    await page.getByLabel(/印尼語/).check();
    await page
        .getByLabel('簡介（選填）')
        .fill('教印尼語五年。\n喜歡用遊戲帶詞彙。');
    await page.screenshot({
        path: testInfo.outputPath('creator-settings.png'),
        fullPage: true,
    });
    await page.getByRole('button', { name: '儲存' }).click();
    await expect(page.getByText('已儲存')).toBeVisible();

    // 自己的創作者頁面：署名、學校、簡介、統計與日曆
    await page.getByRole('link', { name: '查看我的創作者頁面' }).click();
    await page.waitForURL(/\/teachers\/[0-9A-Z]{26}$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(NAME);
    await expect(page.locator('[data-test="teacher-header"]')).toContainText(
        '示範國小',
    );
    await expect(page.locator('[data-test="teacher-bio"]')).toContainText(
        '喜歡用遊戲帶詞彙',
    );
    await expect(page.locator('[data-test="teacher-url"]')).toHaveText(
        'example.org',
    );
    await expect(page.locator('[data-test="teacher-stats"] dd')).toHaveCount(5);
    await expect(
        page.locator('[data-test="contribution-calendar"] tbody tr'),
    ).toHaveCount(7);
    await expect(
        page.getByRole('link', { name: '編輯創作者資料' }),
    ).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await page.screenshot({
        path: testInfo.outputPath('own-profile.png'),
        fullPage: true,
    });

    // 新題組預先選好預設授權；上傳的發音以署名為作者、題組的授權為授權
    await page.goto('/curriculum/id/1/3');
    await page.getByRole('link', { name: '挑詞建立題組' }).click();
    await expect(page.getByLabel('授權')).toHaveValue('CC-BY-SA-4.0');
    await page.getByRole('button', { name: '建立題組' }).click();
    await page.waitForURL('**/sets/*/edit');
    await page.getByLabel(/我有權分享這次上傳的音檔與圖片/).check();
    await page.getByTitle('上傳發音').first().click();
    await page.locator('input[type=file]').first().setInputFiles(wavFile());
    await expect(page.getByTitle('播放發音')).toHaveCount(1);
    const summary = page.locator('[data-test="media-credits-summary"]').first();
    await expect(summary).toHaveText(
        `：${NAME}・CC BY-SA 4.0；Kancil Quiz・CC BY 4.0・AI 生成`,
    );

    // 共備庫：點同事的名字進到他的創作者頁面
    await page.goto('/library');
    const owner = page
        .locator('[data-test="library-set"]')
        .filter({ hasText: '看圖選詞' })
        .locator('[data-test="set-owner"]');
    await expect(owner).toHaveText('示範同事');
    await owner.click();
    await page.waitForURL(/\/teachers\/[0-9A-Z]{26}$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        '示範同事',
    );
    await expect(page.locator('[data-test="teacher-header"]')).toContainText(
        '示範國小',
    );
    await expect(
        page.getByRole('link', { name: '編輯創作者資料' }),
    ).toHaveCount(0);
    // 公開的題組 2 個，待審的不列；日曆上今天有貢獻
    await expect(page.locator('[data-test="library-set"]')).toHaveCount(2);
    await expect(
        page.locator('[data-test="teacher-stats"] dd').first(),
    ).toHaveText('2');
    await expect(
        page.locator(
            '[data-test="contribution-calendar"] td[data-level]:not([data-level="0"])',
        ),
    ).not.toHaveCount(0);
    await expectNoHorizontalOverflow(page);
    await page.screenshot({
        path: testInfo.outputPath('colleague-profile.png'),
        fullPage: true,
    });

    // 手機寬度：日曆在自己的框內捲動，整頁沒有水平捲軸
    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({
        path: testInfo.outputPath('colleague-profile-phone.png'),
        fullPage: true,
    });
    await page.goto('/settings/creator');
    await expectNoHorizontalOverflow(page);
    await page.screenshot({
        path: testInfo.outputPath('creator-settings-phone.png'),
        fullPage: true,
    });

    // 改回來，其他 spec 不受影響
    await page.getByLabel('署名名稱').fill('');
    await page.getByLabel('網址（選填）').fill('');
    await page.getByLabel('預設授權').selectOption('CC-BY-4.0');
    await page.getByRole('button', { name: '儲存' }).click();
    await expect(page.getByText('已儲存')).toBeVisible();

    expect(errors).toEqual([]);
});
