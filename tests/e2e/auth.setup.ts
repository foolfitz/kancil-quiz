import { test as setup } from '@playwright/test';

// 只登入一次並保存登入狀態：Fortify 的登入有限流，每個測試都重新登入會被擋。
export const TEACHER_STATE = 'test-results/.auth/teacher.json';

setup('老師登入', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name=email]', 'teacher@example.com');
    await page.fill('input[name=password]', 'password');
    await page.click('button[type=submit]');
    await page.waitForURL('**/dashboard');
    await page.context().storageState({ path: TEACHER_STATE });
});
