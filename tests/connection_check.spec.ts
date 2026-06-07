import { test, expect } from '@playwright/test';

test('simple connection check', async ({ page }) => {
    console.log('Starting navigation...');
    try {
        const response = await page.goto('/', { timeout: 10000 });
        console.log(`Navigation status: ${response?.status()}`);
        console.log(`Page title: ${await page.title()}`);
        const content = await page.content();
        console.log(`Page content length: ${content.length}`);
    } catch (error) {
        console.error('Navigation failed:', error);
        throw error;
    }

    await expect(page.getByText('Log in to your performance protocol')).toBeVisible({ timeout: 5000 });
});
