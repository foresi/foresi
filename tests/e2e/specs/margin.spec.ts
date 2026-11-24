// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/margin.php: Client A has an invoice of 8,000 with the tax
// point on 2 November 2026, and a received document of 1,500 dated 14 October 2026 that nobody has claimed.
// Client A also bears the planned cost Voice API, paid with a received document on 20 November 2026 and rebilled
// by no invoice, so November is "paid, not rebilled" for it; a recurring invoice line is linked to that cost.
const OWNER = 'margin@example.com';

test.describe('the margin', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
    await signIn(page, OWNER);
  });

  test('a cell opens the documents behind it in the sheet, and the unassigned cost offers to be assigned', async ({ page }) => {
    // The months are fixed in the address, so the spec does not depend on the day it runs.
    await page.goto('/margin?from=2026-10&to=2026-11');
    const table = page.locator('[data-margin-table]');
    await expect(table).toBeVisible();
    await expect(table.locator('th[data-month]')).toHaveCount(2);

    // Client A first, Unassigned last, with the number of documents in a neutral badge.
    await expect(table.locator('tbody[data-row]:not([data-row="total"])').first()).toContainText('Client A s.r.o.');
    await expect(table.locator('tbody[data-row]:not([data-row="total"])').last()).toHaveAttribute('data-row', 'unassigned');
    await expect(table.locator('[data-margin-unassigned-badge]')).toHaveText('1 doklad');

    // The revenue of Client A in November: the invoice, in the sheet.
    await table.locator('tbody[data-row^="client:"] tr[data-line="revenue"] a[data-sheet]').click();
    const sheet = page.locator('dialog#sheet');
    // The revenue section: in November Client A also bears a cost, which has its own section.
    await expect(sheet.locator('[data-margin-section="revenue"] [data-margin-item]')).toHaveCount(1);
    await expect(sheet.locator('[data-margin-total="revenue"]')).toContainText(/8.000,00/);
    await expect(sheet.locator('[data-margin-section="revenue"] [data-margin-item]').first()).toContainText('Faktura');
    await sheet.getByRole('button', { name: 'Zavřít' }).click();
    await expect(sheet).toBeHidden();

    // The cost nobody has claimed in October: the document, and Přiřadit as on the document page.
    await table.locator('tbody[data-row="unassigned"] tr[data-line="costs"] a[data-sheet]').click();
    await expect(sheet.locator('[data-margin-item]')).toHaveCount(1);
    await expect(sheet.locator('[data-margin-item]').first()).toContainText('R20260001');
    await expect(sheet.locator('[data-margin-total="costs"]')).toContainText(/1.500,00/);
    await expect(sheet.getByRole('link', { name: 'Přiřadit' })).toBeVisible();
  });

  test('the months are set in a dialog from the page header', async ({ page }) => {
    await page.goto('/margin');
    await page.locator('[data-margin-range-open]').click();
    const dialog = page.getByRole('dialog', { name: 'Zobrazené měsíce' });
    await dialog.locator('#margin-range-from').fill('2026-10');
    await dialog.locator('#margin-range-to').fill('2026-11');
    await dialog.getByRole('button', { name: 'Zobrazit' }).click();

    await expect(page).toHaveURL(/\/margin\?from=2026-10&to=2026-11/);
    await expect(page.locator('[data-margin-table] th[data-month]')).toHaveCount(2);
  });

  test('a paid cost that no invoice rebills is a neutral badge on the client and listed in the sheet, with its template', async ({ page }) => {
    await page.goto('/margin?from=2026-10&to=2026-11');
    const costs = page.locator('[data-margin-table] tbody[data-row^="client:"] tr[data-line="costs"]');
    await expect(costs.locator('[data-margin-unrebilled]')).toHaveText('Nepřefakturováno 1');

    await costs.locator('a[data-sheet]').last().click();
    const sheet = page.locator('dialog#sheet');
    const unrebilled = sheet.locator('[data-margin-unrebilled-item]');
    await expect(unrebilled).toHaveCount(1);
    await expect(unrebilled).toContainText('Voice API');
    await expect(unrebilled).toContainText('2026-11-20');
    await expect(unrebilled.locator('[data-margin-unrebilled-item-link]')).toHaveAttribute('href', /\/cash-flow\/planned-costs\//);

    // The template opens in the sheet; its line shows the cost it rebills and offers the dialog from its row menu.
    await unrebilled.locator('[data-margin-unrebilled-template-link]').click();
    const line = sheet.locator('[data-recurring-line]');
    await expect(line.locator('[data-margin-rebill-link]')).toContainText('Přefakturovává Voice API');
    await line.getByRole('button', { name: 'Akce' }).click();
    await line.locator('[data-margin-rebill-open]').click();
    const dialog = page.getByRole('dialog', { name: 'Přefakturovává náklad' });
    await expect(dialog.locator('#margin-rebill-item')).toBeVisible();
  });
});
