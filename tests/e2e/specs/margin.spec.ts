// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/margin.php: Client A has an invoice of 8,000 with the tax
// point on 2 November 2026, and a received document of 1,500 dated 14 October 2026 that nobody has claimed.
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
    await expect(sheet.locator('[data-margin-item]')).toHaveCount(1);
    await expect(sheet.locator('[data-margin-total="revenue"]')).toContainText(/8.000,00/);
    await expect(sheet.locator('[data-margin-item]').first()).toContainText('Faktura');
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
});
