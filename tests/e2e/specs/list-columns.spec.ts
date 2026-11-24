// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { OWNER_STATE } from '../support/session';

test.use({ storageState: OWNER_STATE });

// The Columns sheet of a list (spec D-display-4, D-display-6): show, hide and reorder the columns,
// with a single pointer and with keys, and the row height; the list keeps it in its address.
test.describe('list columns', () => {
  test('hides, moves and compacts the columns with the keyboard, and resets them', async ({ page }) => {
    await page.goto('/contacts');
    const open = page.getByRole('button', { name: /^Nastavení seznamu/ });
    await open.focus();
    await page.keyboard.press('Enter');
    const sheet = page.getByRole('dialog', { name: 'Nastavení seznamu' });
    await expect(sheet).toBeVisible();
    // The Settings sheet also lists the quick filters; the columns are their own group.
    const columns = sheet.getByRole('group', { name: 'Sloupce' });

    // The column that names the row is always shown and cannot be unchecked.
    await expect(columns.getByRole('checkbox', { name: /Název/ })).toBeDisabled();
    await columns.getByRole('checkbox', { name: 'Role' }).focus();
    await page.keyboard.press('Space');

    // Move up keeps the focus and says where the column went.
    await columns.getByRole('button', { name: 'Posunout Stav nahoru' }).focus();
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await expect(columns.getByRole('button', { name: 'Posunout Stav nahoru' })).toBeFocused();
    await expect(sheet.getByRole('status')).toHaveText('Stav je teď na pozici 5 z 7.');

    await sheet.getByRole('radio', { name: /Kompaktní/ }).check();
    await sheet.getByRole('button', { name: 'Použít' }).click();

    await expect(page).toHaveURL(/cols=name%2Ckind%2Cregistration_number%2Cstatus%2Cvat_id%2Cemail&density=dense/);
    await expect(page.locator('thead th')).toHaveText(['', 'Název', 'Druh', 'IČO', 'Stav', 'DIČ', 'E-mail']);
    await expect(page.getByRole('link', { name: /Všechny.*neuložené změny/ })).toBeVisible();

    // A sort keeps the changed columns.
    await page.getByRole('link', { name: /^Druh/ }).click();
    await expect(page).toHaveURL(/sort=kind.*cols=/);
    await expect(page.locator('thead th')).toHaveText(['', 'Název', 'Druh', 'IČO', 'Stav', 'DIČ', 'E-mail']);

    await page.getByRole('button', { name: /^Nastavení seznamu/ }).click();
    await page.getByRole('dialog', { name: 'Nastavení seznamu' }).getByRole('link', { name: 'Obnovit výchozí' }).click();
    await expect(page).not.toHaveURL(/cols=/);
    await expect(page.locator('thead th')).toHaveText(['', 'Název', 'Druh', 'Role', 'IČO', 'DIČ', 'E-mail', 'Stav']);
  });

  test('adds a column from the attributes the list does not show', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('button', { name: /^Nastavení seznamu/ }).click();
    const sheet = page.getByRole('dialog', { name: 'Nastavení seznamu' });
    const add = sheet.getByRole('group', { name: 'Přidat sloupec' });

    // The search goes over what is shown and what can be added.
    await sheet.getByRole('searchbox', { name: 'Hledat sloupce' }).fill('telef');
    await expect(add.getByRole('checkbox')).toHaveCount(1);
    await add.getByRole('checkbox', { name: 'Telefon' }).check();
    await sheet.getByRole('button', { name: 'Použít' }).click();

    await expect(page).toHaveURL(/cols=name%2Ckind%2Croles%2Cregistration_number%2Cvat_id%2Cemail%2Cstatus%2Cphone/);
    await expect(page.locator('thead th')).toHaveText(['', 'Název', 'Druh', 'Role', 'IČO', 'DIČ', 'E-mail', 'Stav', 'Telefon']);

    // The list remembers the changed columns for the person (D-display-3): opened again without
    // any in the address, it still shows the added one, until Reset forgets them.
    await page.goto('/contacts');
    await expect(page.locator('thead th')).toHaveText(['', 'Název', 'Druh', 'Role', 'IČO', 'DIČ', 'E-mail', 'Stav', 'Telefon']);
    await page.getByRole('button', { name: /^Nastavení seznamu/ }).click();
    await page.getByRole('dialog', { name: 'Nastavení seznamu' }).getByRole('link', { name: 'Obnovit výchozí' }).click();
    await expect(page).not.toHaveURL(/cols=/);
    await page.goto('/contacts');
    await expect(page.locator('thead th')).toHaveText(['', 'Název', 'Druh', 'Role', 'IČO', 'DIČ', 'E-mail', 'Stav']);
  });

  test('moves a column by dragging its handle', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('button', { name: /^Nastavení seznamu/ }).click();
    const sheet = page.getByRole('dialog', { name: 'Nastavení seznamu' });
    // The Settings sheet also lists the quick filters (Druh is one); the columns are their own group.
    const columns = sheet.getByRole('group', { name: 'Sloupce' });
    const handle = columns.locator('[data-key="email"] [data-column-picker-handle]');
    const target = columns.locator('[data-key="kind"]');
    // The columns stand under the views and the quick filters in the sheet: bring them into view.
    await target.scrollIntoViewIfNeeded();
    const from = await handle.boundingBox();
    const to = await target.boundingBox();
    if (from === null || to === null) {
      throw new Error('The rows of the sheet are not laid out.');
    }
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(to.x + to.width / 2, to.y + 2, { steps: 8 });
    await page.mouse.up();
    await expect(sheet.getByRole('status')).toHaveText('E-mail je teď na pozici 2 z 7.');
    await sheet.getByRole('button', { name: 'Použít' }).click();
    await expect(page.locator('thead th')).toHaveText(['', 'Název', 'E-mail', 'Druh', 'Role', 'IČO', 'DIČ', 'Stav']);
  });
});
