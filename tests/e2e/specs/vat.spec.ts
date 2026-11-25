// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '../support/test';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/vat.php: a monthly VAT payer from 12 November 2026 with one
// issued invoice of 9,000 + 21 %, and a guest of its customer.
const OWNER = 'vat@example.com';
const GUEST = 'vat-guest@example.com';

test.describe('VAT', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
  });

  test('staff open the VAT page, the period of November and enter the amount of the return', async ({ page }) => {
    await signIn(page, OWNER);
    await page.goto('/cash-flow');
    await page.locator('#sidebar').getByRole('link', { name: 'DPH', exact: true }).click();
    await expect(page).toHaveURL(/\/vat$/);
    await expect(page.getByRole('heading', { name: 'DPH', level: 1 })).toBeVisible();

    // November 2026, whichever year it is today.
    await page.goto('/vat?year=2026');
    const november = page.locator('[data-vat-period="2026-11"]');
    await expect(november).toContainText('listopad 2026');
    await expect(november.locator('[data-vat-result]')).toContainText(/K zaplacení\s+1\s890\sKč/);
    await expect(november.locator('[data-vat-due]')).toHaveText('28. 12. 2026');
    // Neutral or brand, never red and never "overdue".
    await expect(november.locator('[data-vat-state] .bg-badge-red')).toHaveCount(0);
    await expect(page.locator('[data-vat-periods]')).not.toContainText('po splatnosti');
    await expect(page.locator('[data-vat-footer]')).toContainText('Odhad, ne podklad pro přiznání.');

    // The period opens in the side sheet over the page.
    await november.getByRole('link', { name: /listopad 2026/ }).click();
    const sheet = page.locator('dialog#sheet');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('heading', { name: 'listopad 2026' })).toBeVisible();
    await expect(sheet.locator('[data-vat-part="plans"]')).toContainText('Plány');
    await expect(sheet.locator('[data-vat-document]')).toHaveCount(1);

    // The amount of the return in a dialog over the sheet.
    await sheet.getByRole('link', { name: 'Zadat částku z přiznání' }).click();
    const dialog = page.locator('dialog#dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Částka').fill('1 500');
    await dialog.getByRole('button', { name: 'Uložit' }).click();
    await expect(dialog).toBeHidden();
    await expect(sheet.locator('[data-vat-return-amount-text]')).toContainText(/Částka z přiznání: 1\s500\sKč, odhad byl 1\s890\sKč\./);
    await expect(sheet.locator('[data-vat-summary]')).toContainText(/K zaplacení\s+1\s500\sKč/);
  });

  test('the VAT line in the cash flow is an estimate and the source filter leaves it out', async ({ page }) => {
    await signIn(page, OWNER);
    await page.goto('/cash-flow');
    const vat = page.locator('[data-cash-flow-table] tr[data-row="category"]', { hasText: 'DPH' }).first();
    await expect(vat).toBeVisible();
    await vat.locator('a[data-sheet]').first().click();
    const sheet = page.locator('dialog#sheet');
    await expect(sheet.locator('[data-cash-flow-item]').first().locator('[data-certainty]')).toHaveText('Odhad daní, pojištění a DPH');
    await sheet.getByRole('button', { name: 'Zavřít' }).first().click();

    // "Zdroj není Odhad daní, pojištění a DPH" leaves the VAT out; the invoice stays.
    // The filter icon at the end of the tabs opens the filter sheet at once (Shopify Polaris IndexFilters).
    await page.locator('[data-filter-toggle]').click();
    await page.getByRole('button', { name: 'Přidat filtr' }).click();
    await page.getByRole('menuitem', { name: 'Zdroj' }).click();
    const row = page.locator('[data-filter-sheet-form] [data-filter-row="certainty"]');
    await row.getByRole('combobox').first().selectOption({ label: 'není' });
    await row.getByLabel('Odhad daní, pojištění a DPH', { exact: true }).check();
    await page.getByRole('button', { name: 'Použít' }).click();
    await expect(page).toHaveURL(/[?&]f=/);
    await expect(page.locator('[data-cash-flow-filter]')).toContainText('Zdroj: není Odhad daní, pojištění a DPH');
    await expect(page.locator('[data-cash-flow-table] tr[data-row="category"]', { hasText: 'DPH' })).toHaveCount(0);
  });

  test('a guest does not see the menu item', async ({ page }) => {
    await signIn(page, GUEST);
    await expect(page.locator('#sidebar').getByRole('link', { name: 'DPH', exact: true })).toHaveCount(0);
    const response = await page.goto('/vat');
    expect(response?.status()).toBe(403);
  });
});
