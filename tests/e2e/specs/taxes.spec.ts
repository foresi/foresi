// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/taxes.php: a sole trader without payments yet, and a team member.
const OWNER = 'taxes@example.com';
const MEMBER = 'taxes-member@example.com';

test.describe('taxes and contributions', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
  });

  test('the owner sees what they keep and opens the steps of social insurance', async ({ page }) => {
    await signIn(page, OWNER);
    await page.goto('/cash-flow');
    await page.locator('#sidebar').getByRole('link', { name: 'Daně a odvody' }).click();
    await expect(page).toHaveURL(/\/taxes-and-contributions$/);

    const summary = page.locator('[data-taxes-summary]');
    await expect(summary.getByRole('heading', { name: 'Kolik ti zůstane' })).toBeVisible();
    await expect(summary.getByRole('columnheader', { name: 'Zatím' })).toBeVisible();
    await expect(summary.getByRole('columnheader', { name: 'Na konci roku' })).toBeVisible();
    // The year end is a forecast, apart from the facts, with its part split by certainty.
    await expect(summary.locator('[data-taxes-year-end] [data-taxes-forecast-badge]')).toHaveText('Výhled');
    await expect(summary.locator('[data-taxes-year-end-cell="social"] a')).toBeVisible();
    await expect(summary.locator('[data-taxes-forecast]')).toContainText('Otevřené doklady');
    await expect(page.locator('[data-taxes-footer]')).toContainText('Odhad, ne podklad pro přiznání.');

    // A number opens its steps in the side sheet over the page, as a cell of Cash flow opens its items.
    await page.locator('[data-taxes-row="social"] td:not([data-taxes-year-end-cell]) a').click();
    const sheet = page.locator('dialog#sheet');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('heading', { name: 'Sociální pojištění · Skutečné výdaje' })).toBeVisible();
    await expect(sheet.locator('[data-taxes-step="social"] [data-taxes-step-rule]')).toContainText('Podíl ze zisku');
    await expect(sheet.getByRole('link', { name: /Zdroj pravidla/ })).toHaveAttribute('href', /^https:\/\//);

    await sheet.getByRole('button', { name: 'Zavřít' }).first().click();
    await expect(sheet).toBeHidden();
    await expect(page.locator('[data-taxes-summary]')).toBeVisible();

    // A number of the year end opens the steps of the year end.
    await page.locator('[data-taxes-year-end-cell="social"] a').click();
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('heading', { name: 'Sociální pojištění na konci roku · Skutečné výdaje' })).toBeVisible();
  });

  test('the payments fall due on the page and reach the cash flow as estimates', async ({ page }) => {
    await signIn(page, OWNER);
    await page.goto('/taxes-and-contributions');
    const payments = page.locator('[data-taxes-payments]');
    await expect(payments.getByRole('heading', { name: 'Platby' })).toBeVisible();
    // No bank account pays them yet: what is past is overdue, the rest is expected.
    await expect(payments.locator('tr[data-state="expected"]').first()).toContainText('Očekává se');
    await expect(payments.locator('tr[data-taxes-payment^="social.advance."]').first()).toContainText('Sociální pojištění');

    // In the cash flow the advances are estimates, in their own outlined badge.
    await page.goto('/cash-flow');
    const insurance = page.locator('[data-cash-flow-table] tr[data-row="category"]', { hasText: 'Pojištění' }).first();
    await expect(insurance).toBeVisible();
    await insurance.locator('a[data-sheet]').first().click();
    const sheet = page.locator('dialog#sheet');
    await expect(sheet.locator('[data-cash-flow-item]').first().locator('[data-certainty]')).toHaveText('Odhad daní, pojištění a DPH');
    await sheet.getByRole('button', { name: 'Zavřít' }).first().click();

    // The filter "Zdroj není Odhad daní, pojištění a DPH" leaves them out, and with them every flow of this firm.
    // The filter icon at the end of the tabs opens the filter sheet at once (Shopify Polaris IndexFilters).
    await page.locator('[data-filter-toggle]').click();
    await page.getByRole('button', { name: 'Přidat filtr' }).click();
    await page.getByRole('menuitem', { name: 'Zdroj' }).click();
    const row = page.locator('[data-filter-sheet-form] [data-filter-row="certainty"]');
    await row.getByRole('combobox').first().selectOption({ label: 'není' });
    await row.getByLabel('Odhad daní, pojištění a DPH', { exact: true }).check();
    await page.getByRole('button', { name: 'Použít' }).click();
    await expect(page).toHaveURL(/[?&]f=/);
    await expect(page.locator('[data-cash-flow-table-empty]')).toBeVisible();
    await expect(page.locator('[data-cash-flow-filter]')).toContainText('Zdroj: není Odhad daní, pojištění a DPH');
  });

  test('a team member does not see the menu item', async ({ page }) => {
    await signIn(page, MEMBER);
    await page.goto('/cash-flow');
    await expect(page.locator('#sidebar').getByRole('link', { name: 'Daně a odvody' })).toHaveCount(0);
    const response = await page.goto('/taxes-and-contributions');
    expect(response?.status()).toBe(403);
  });
});
