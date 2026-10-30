// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { EMPTY_OWNER_STATE, OWNER_STATE } from '../support/session';

test.use({ storageState: OWNER_STATE });

async function openContact(page: Page, name: string): Promise<void> {
  await page.goto('/contacts');
  await page.getByRole('link', { name, exact: true }).click();
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
}

test.describe('list, search and views', () => {
  test('searches the list and clears the search', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('searchbox', { name: 'Search contacts' }).fill('Alpha Example');
    await page.getByRole('button', { name: 'Search', exact: true }).click();

    await expect(page).toHaveURL(/q=Alpha/);
    const rows = page.locator('tbody tr');
    expect(await rows.count()).toBeGreaterThan(0);
    for (const row of await rows.all()) {
      await expect(row).toContainText('Alpha Example');
    }

    await page.getByRole('searchbox', { name: 'Search contacts' }).fill('no such contact anywhere');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Nothing found' })).toBeVisible();
    await page.getByRole('link', { name: 'Clear filter' }).click();
    await expect(page.locator('tbody tr').first()).toBeVisible();
  });

  test('switches between the views', async ({ page }) => {
    await page.goto('/contacts');
    const views = page.getByRole('navigation', { name: 'Contact views' });
    await expect(views.getByRole('link', { name: /^All/ })).toHaveAttribute('aria-current', 'page');

    await views.getByRole('link', { name: /^Suppliers/ }).click();
    await expect(page).toHaveURL(/view=suppliers/);
    await expect(views.getByRole('link', { name: /^Suppliers/ })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('link', { name: 'Example Supplies s.r.o.', exact: true })).toBeVisible();

    await views.getByRole('link', { name: /^Archived/ }).click();
    await expect(page).toHaveURL(/view=archived/);
  });

  test('shows long names truncated, with the full value available', async ({ page }) => {
    await page.goto('/contacts');
    const long = 'Example International Wholesale and Distribution Company Ltd';
    const link = page.getByRole('link', { name: long });
    await expect(link).toBeVisible();
    // The cell is shorter than the text, and the table does not overflow its panel.
    const overflowing = await link.evaluate((element) => element.scrollWidth > element.clientWidth);
    expect(overflowing).toBe(true);
    const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(pageOverflow).toBe(false);
  });

  test('a contact that does not exist answers 404', async ({ page }) => {
    const response = await page.goto('/contacts/999999');
    expect(response?.status()).toBe(404);
  });
});

test.describe('layout', () => {
  for (const [name, path] of [['list', '/contacts'], ['form', '/contacts/new']] as const) {
    test(`the ${name} does not scroll sideways @layout`, async ({ page }) => {
      await page.goto(path);
      await expect(page.locator('main')).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow).toBeLessThanOrEqual(0);
    });
  }

  test('the detail does not scroll sideways @layout', async ({ page }) => {
    await openContact(page, 'Example Supplies s.r.o.');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

test.describe('screens', () => {
  test.use({ storageState: OWNER_STATE });

  test('contact list looks as approved @visual', async ({ page }) => {
    await page.goto('/contacts');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expect(page).toHaveScreenshot('list.png', { fullPage: true });
  });

  test('contact detail looks as approved @visual', async ({ page }) => {
    await openContact(page, 'Example Supplies s.r.o.');
    await expect(page).toHaveScreenshot('detail.png', { fullPage: true });
  });

  test('contact form looks as approved @visual', async ({ page }) => {
    await page.goto('/contacts/new');
    await expect(page.locator('#displayName')).toBeVisible();
    await expect(page).toHaveScreenshot('form.png', { fullPage: true });
  });

});

test.describe('first run', () => {
  test.use({ storageState: EMPTY_OWNER_STATE });

  test('empty address book looks as approved @visual', async ({ page }) => {
    await page.goto('/contacts');
    await expect(page.getByText('Your address book is empty')).toBeVisible();
    await expect(page).toHaveScreenshot('empty.png', { fullPage: true });
  });
});
