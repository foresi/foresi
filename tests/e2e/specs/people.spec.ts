// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { signIn } from '../support/session';

// The owner of "Example People" in tests/e2e/seed.php; the e2e installation has no system mail.
const PEOPLE_OWNER = 'people@example.com';

async function invite(page: Page, email: string, name: string): Promise<string> {
  await page.goto('/settings/people');
  await page.getByRole('link', { name: 'Pozvat' }).click();
  await page.locator('#email').fill(email);
  await page.locator('#name').fill(name);
  await page.getByRole('button', { name: 'Odeslat pozvánku' }).click();
  await expect(page.getByText('Tato instalace zatím neumí posílat e-maily')).toBeVisible();

  return page.locator('#invitation-link').inputValue();
}

test.describe('people of the organisation', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, PEOPLE_OWNER);
  });

  test('without system mail the owner copies the link, and the invited person joins through it', async ({ page, browser }) => {
    const link = await invite(page, 'new-member@example.com', 'New Member');
    expect(link).toMatch(/\/invitation\/[A-Za-z0-9_-]{43}$/);
    await page.getByRole('button', { name: 'Kopírovat odkaz' }).click();

    const guest = await browser.newContext({ locale: 'cs-CZ' });
    const visitor = await guest.newPage();
    await visitor.goto(link);
    await expect(visitor.getByRole('heading', { name: 'Připojit se k Example People' })).toBeVisible();
    await visitor.locator('#password').fill('a-long-password-1');
    await visitor.locator('#password_repeat').fill('a-long-password-1');
    await visitor.getByRole('button', { name: 'Vytvořit účet a připojit se' }).click();
    // Two-factor sign-in is not required in the e2e environment, so the new member lands in the app.
    await expect(visitor).toHaveURL(/\/$/);
    await expect(visitor.locator('#sidebar')).toContainText('Lidé');

    // The link worked once.
    await visitor.goto(link);
    await expect(visitor.getByRole('heading', { name: 'Pozvánka použita' })).toBeVisible();
    await guest.close();

    await page.goto('/settings/people');
    const row = page.locator('tbody tr').filter({ hasText: 'new-member@example.com' });
    await expect(row).toContainText('New Member');
    await expect(row).toContainText('Člen');
  });

  test('the owner revokes a pending invitation and its link stops working', async ({ page, browser }) => {
    const link = await invite(page, 'revoked@example.com', 'Revoked Example');
    await page.getByRole('link', { name: 'Hotovo' }).click();

    const row = page.locator('tbody tr').filter({ hasText: 'revoked@example.com' });
    await row.getByRole('button', { name: 'Další akce pro Revoked Example', exact: true }).click();
    await row.getByRole('menuitem', { name: 'Zrušit pozvánku' }).click();
    const confirm = page.getByRole('alertdialog', { name: 'Zrušit pozvánku pro revoked@example.com?' });
    await confirm.getByRole('button', { name: 'Zrušit pozvánku' }).click();
    await expect(page.locator('main')).toContainText('Pozvánka je zrušená.');
    await expect(page.locator('tbody tr').filter({ hasText: 'revoked@example.com' })).toHaveCount(0);

    const guest = await browser.newContext({ locale: 'cs-CZ' });
    const visitor = await guest.newPage();
    await visitor.goto(link);
    await expect(visitor.getByRole('heading', { name: 'Pozvánka zrušena' })).toBeVisible();
    await guest.close();
  });
});
