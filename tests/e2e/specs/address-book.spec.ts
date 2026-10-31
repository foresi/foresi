// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { expectRowControlsAligned } from '../support/layout';
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
    await page.getByRole('searchbox', { name: 'Hledat v kontaktech' }).fill('Alpha Example');
    await page.getByRole('button', { name: 'Hledat', exact: true }).click();

    await expect(page).toHaveURL(/q=Alpha/);
    const rows = page.locator('tbody tr');
    expect(await rows.count()).toBeGreaterThan(0);
    for (const row of await rows.all()) {
      await expect(row).toContainText('Alpha Example');
    }

    await page.getByRole('searchbox', { name: 'Hledat v kontaktech' }).fill('no such contact anywhere');
    await page.getByRole('button', { name: 'Hledat', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Nic nenalezeno' })).toBeVisible();
    await page.getByRole('link', { name: 'Zrušit filtr' }).click();
    await expect(page.locator('tbody tr').first()).toBeVisible();
  });

  test('offers the export as CSV or Excel from a menu and downloads the file', async ({ page }) => {
    await page.goto('/contacts');
    const menu = page.getByRole('menu');
    await expect(menu).toBeHidden();
    await page.getByRole('button', { name: 'Exportovat' }).click();
    await expect(menu.getByRole('menuitem')).toHaveText(['Exportovat jako CSV', 'Exportovat jako Excel (XLSX)']);

    const download = page.waitForEvent('download');
    await menu.getByRole('menuitem', { name: /Excel/ }).click();
    expect((await download).suggestedFilename()).toMatch(/^contacts-\d{8}\.xlsx$/);
  });

  test('switches between the views', async ({ page }) => {
    await page.goto('/contacts');
    const views = page.getByRole('navigation', { name: 'Zobrazení kontaktů' });
    await expect(views.getByRole('link', { name: /^Všechny/ })).toHaveAttribute('aria-current', 'page');

    await views.getByRole('link', { name: /^Dodavatelé/ }).click();
    await expect(page).toHaveURL(/view=suppliers/);
    await expect(views.getByRole('link', { name: /^Dodavatelé/ })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('link', { name: 'Example Supplies s.r.o.', exact: true })).toBeVisible();

    await views.getByRole('link', { name: /^Archivované/ }).click();
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

  test('fields side by side on the contact form line up, with and without errors @layout', async ({ page }) => {
    await page.goto('/contacts/new');
    await expectRowControlsAligned(page.locator('main form'));

    // Errors under some fields of a row must not push the controls of the others down.
    // A form that fails validation saves nothing.
    await page.getByRole('button', { name: 'Uložit' }).click();
    await expect(page.locator('#displayName')).toHaveAttribute('aria-invalid', 'true');
    await expectRowControlsAligned(page.locator('main form'));
  });

  test('the detail does not scroll sideways @layout', async ({ page }) => {
    await openContact(page, 'Example Supplies s.r.o.');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

// Screenshots show what a user sees: the viewport (shell with sidebar, as the browser renders it),
// and, where the page is longer than the viewport, a second image of the main content alone.
// Full-page captures are avoided: they stretch the sticky sidebar and misplace its user card.
test.describe('screens', () => {
  test.use({ storageState: OWNER_STATE });

  test('contact list looks as approved @visual', async ({ page }) => {
    await page.goto('/contacts');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expect(page).toHaveScreenshot('list.png');
    await expect(page.locator('main')).toHaveScreenshot('list-content.png');
  });

  test('contact detail looks as approved @visual', async ({ page }) => {
    await openContact(page, 'Example Supplies s.r.o.');
    await expect(page).toHaveScreenshot('detail.png');
    await expect(page.locator('main')).toHaveScreenshot('detail-content.png');
  });

  test('contact form looks as approved @visual', async ({ page }) => {
    await page.goto('/contacts/new');
    await expect(page.locator('#displayName')).toBeVisible();
    await expect(page).toHaveScreenshot('form.png');
    // The content capture shows the docked action bar where it sits at the end of the form.
    await page.addStyleTag({ content: '[data-action-bar] { position: static !important; }' });
    await page.locator('[data-action-bar]').evaluate((bar) => bar.removeAttribute('data-stuck'));
    await expect(page.locator('main')).toHaveScreenshot('form-content.png');
  });

});

test.describe('first run', () => {
  test.use({ storageState: EMPTY_OWNER_STATE });

  test('empty address book looks as approved @visual', async ({ page }) => {
    await page.goto('/contacts');
    await expect(page.getByText('V adresáři zatím nic není')).toBeVisible();
    await expect(page).toHaveScreenshot('empty.png');
  });
});
