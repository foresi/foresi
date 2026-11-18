// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { OWNER_STATE } from '../support/session';

// Ui:Sheet on its live catalogue sample: the real host, the real controllers.
test.describe('side sheet', () => {
  test('a detail sheet opens at the right edge, resizes by keyboard and closes back to its opener', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/_catalogue#component-sheet');
    const opener = page.getByRole('link', { name: 'Detail · 640 px' });
    await opener.click();

    const sheet = page.locator('dialog#sheet').and(page.getByRole('dialog', { name: 'Faktura 20260001' }));
    await expect(sheet).toBeVisible();
    expect(await sheet.evaluate((element) => element.matches(':modal'))).toBe(true);
    await expect(sheet.getByRole('heading', { name: 'Faktura 20260001' })).toBeFocused();
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(640);
    await expect.poll(async () => { const box = await sheet.boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(1440);

    // APG window splitter: the arrow keys move the left edge by 16 px, and the width is remembered.
    const grip = sheet.getByRole('separator', { name: 'Změnit šířku panelu' });
    await grip.focus();
    await page.keyboard.press('ArrowLeft');
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(656);
    await expect(grip).toHaveAttribute('aria-valuenow', '656');
    await page.keyboard.press('Home');
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(420);

    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();
    await expect(opener).toBeFocused();

    await opener.click();
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(420);
    await sheet.getByRole('button', { name: 'Zavřít' }).first().click();
    await expect(sheet).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('a filter sheet is 400 px and has no grip; on a phone a sheet fills the screen', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/_catalogue#component-sheet');
    await page.getByRole('link', { name: 'Filtry · 400 px' }).click();
    const sheet = page.locator('dialog#sheet').and(page.getByRole('dialog', { name: 'Filtry' }));
    await expect(sheet).toBeVisible();
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(400);
    await expect(sheet.getByRole('separator')).toHaveCount(0);
    // A click on the scrim closes it.
    await page.mouse.click(200, 450);
    await expect(sheet).toBeHidden();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('link', { name: 'Detail · 640 px' }).click();
    const detail = page.locator('dialog#sheet').and(page.getByRole('dialog', { name: 'Faktura 20260001' }));
    await expect(detail).toBeVisible();
    await expect.poll(async () => (await detail.boundingBox())?.width).toBe(390);
    await expect(detail.getByRole('separator')).toBeHidden();
  });
});

// A record of a list opens in the side sheet (docs/design/overlays.md): the Contacts pilot.
test.describe('a record in the side sheet', () => {
  test.use({ storageState: OWNER_STATE });

  const NAME = 'Example Supplies s.r.o.';

  test('a contact opens from the list with its URL, Back closes it and it edits in place', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/contacts');
    const row = page.getByRole('link', { name: NAME, exact: true });
    const href = await row.getAttribute('href');
    await row.click();

    const sheet = page.locator('dialog#sheet').and(page.getByRole('dialog', { name: NAME }));
    await expect(sheet).toBeVisible();
    // The record's own URL while it is open; the list stays under it.
    await expect(page).toHaveURL(new RegExp(`${href}$`));
    await expect(page.getByRole('heading', { level: 1, name: 'Adresář' })).toBeAttached();
    // The page header's actions, in the sheet's header.
    const edit = sheet.getByRole('link', { name: 'Upravit' });
    await expect(edit).toBeVisible();

    // Back closes it and gives the list its URL back.
    await page.goBack();
    await expect(sheet).toBeHidden();
    await expect(page).toHaveURL(/\/contacts$/);
    await expect(row).toBeFocused();

    // The whole row opens it too (index rows), and Full screen takes the width of the content panel.
    await page.locator('tr', { has: row }).locator('td').nth(2).click();
    await expect(sheet).toBeVisible();
    const full = sheet.getByRole('button', { name: 'Celá obrazovka' });
    await full.click();
    await expect(full).toHaveAttribute('aria-pressed', 'true');
    const panel = await page.locator('#main').boundingBox();
    await expect.poll(async () => Math.round((await sheet.boundingBox())?.x ?? 0)).toBe(Math.round(Math.max(0, panel?.x ?? 0)));
    await full.click();
    await expect(full).toHaveAttribute('aria-pressed', 'false');
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBeLessThan(1000);

    // Edit in place: the form in the same host, Save shows the detail again.
    const host = page.locator('dialog#sheet');
    await sheet.getByRole('link', { name: 'Upravit' }).click();
    await expect(host.getByRole('heading', { name: 'Upravit', exact: true })).toBeVisible();
    await expect(host.locator('#displayName')).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`${href}/edit$`));
    await host.locator('#phone').fill('+420 600 000 111');
    await host.getByRole('button', { name: 'Uložit' }).click();
    await expect(sheet).toBeVisible();
    await expect(host.locator('#displayName')).toHaveCount(0);
    await expect(sheet).toContainText('+420 600 000 111');
    await expect(page).toHaveURL(new RegExp(`${href}$`));

    // Closing goes back to the list, loaded again.
    await sheet.getByRole('button', { name: 'Zavřít' }).first().click();
    await expect(sheet).toBeHidden();
    await expect(page).toHaveURL(/\/contacts$/);

    // Reload with the record's URL is its page.
    await page.goto(href ?? '/');
    await expect(page.getByRole('heading', { level: 1, name: NAME })).toBeVisible();
    await expect(page.locator('dialog#sheet')).not.toHaveAttribute('open');
  });

  test('open as page visits the record after the list; on a phone the sheet fills the screen', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/contacts');
    await page.getByRole('link', { name: NAME, exact: true }).click();
    const sheet = page.locator('dialog#sheet').and(page.getByRole('dialog', { name: NAME }));
    await expect(sheet).toBeVisible();
    await sheet.getByRole('link', { name: 'Otevřít jako stránku' }).click();
    await expect(page.getByRole('heading', { level: 1, name: NAME })).toBeVisible();
    await expect(sheet).toBeHidden();
    await page.goBack();
    await expect(page).toHaveURL(/\/contacts$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Adresář' })).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('link', { name: NAME, exact: true }).click();
    await expect(sheet).toBeVisible();
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(390);
    await expect(sheet.getByRole('button', { name: 'Celá obrazovka' })).toBeHidden();
    await page.goBack();
    await expect(sheet).toBeHidden();
    await expect(page).toHaveURL(/\/contacts$/);
  });
});
