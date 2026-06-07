import { test, expect } from '@playwright/test';

test.describe('Authentication Flow', () => {

    test('should allow a user to register and login', async ({ page }) => {
        const timestamp = Date.now();
        const email = `testuser_${timestamp}@zenith.com`;
        const password = 'securePassword123';
        const name = 'Test User';

        // 1. Visit Home
        await page.goto('/');

        // Check if we are on Login page
        await expect(page.getByText('Log in to your performance protocol')).toBeVisible();

        // 2. Switch to Register
        await page.getByText('Start Application').click();
        await expect(page.getByText('Initialize your metabolic profile')).toBeVisible();

        // 3. Fill Registration Form
        await page.getByLabel('Full Name').fill(name);
        await page.getByLabel('Email Protocol').fill(email);
        await page.getByLabel('Security Key').fill(password);

        // 4. Submit
        await page.getByRole('button', { name: 'Create Protocol ID' }).click();

        // 5. Verify Redirect to Dashboard (Mock or Real)
        // After login, the Dashboard usually shows "Welcome Back" or "Focus"
        // Let's wait for navigation or a dashboard element
        await expect(page.locator("h1")).toContainText('The Waiting Room', { timeout: 10000 });

        // 6. Verify Session Storage (Optional)
        // const session = await page.evaluate(() => localStorage.getItem('zenith_session'));
        // expect(session).toBeTruthy();
    });

    test('should show error for invalid login', async ({ page }) => {
        await page.goto('/');

        await page.getByLabel('Email Protocol').fill('invalid@zenith.com');
        await page.getByLabel('Security Key').fill('wrongpassword');
        await page.getByRole('button', { name: 'Initialize Session' }).click();

        await expect(page.locator('.text-rose-500')).toContainText('Invalid credentials.');
    });

});
