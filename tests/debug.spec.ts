import { test } from '@playwright/test';

test('debug console logs', async ({ page }) => {
    page.on('console', msg => console.log(`[BROWSER] ${msg.type()}: ${msg.text()}`));
    page.on('pageerror', err => console.log(`[BROWSER ERROR] ${err.message}`));

    await page.goto('/');
    await page.waitForTimeout(5000);
    const text = await page.evaluate(() => document.body.innerText);
    console.log('--- PAGE TEXT ---');
    console.log(text);
    console.log('--- END TEXT ---');
});
