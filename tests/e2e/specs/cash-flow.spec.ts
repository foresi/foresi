// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/cash-flow.php: a firm with a bank account and one open
// invoice, so the forecast has a document and no estimate.
const OWNER = 'cash-flow@example.com';

test.describe('the cash flow', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
    await signIn(page, OWNER);
  });

  test('the certainty filter "Odhad" without estimates shows the empty state of the table', async ({ page }) => {
    await page.goto('/cash-flow');
    await expect(page.locator('[data-cash-flow-table]')).toBeVisible();

    // Certainty is a field of "+ Filter", sent with Apply as a condition in f=, as on the list pages.
    await page.getByRole('button', { name: 'Filtr', exact: true }).click();
    await page.getByRole('button', { name: 'Přidat filtr' }).click();
    await page.getByRole('menuitem', { name: 'Zdroj' }).click();
    await page.locator('[data-filter-sheet-form] [data-filter-row="certainty"]').getByLabel('Odhad daní, pojištění a DPH', { exact: true }).check();
    await page.getByRole('button', { name: 'Použít' }).click();

    await expect(page).toHaveURL(/[?&]f=/);
    await expect(page.locator('[data-cash-flow-table-empty]')).toBeVisible();
    await expect(page.locator('[data-cash-flow-table]')).toHaveCount(0);
    await expect(page.locator('[data-cash-flow-filter]')).toContainText('Zdroj: Odhad daní, pojištění a DPH');
    // The figures stay: the balance today is never filtered.
    await expect(page.locator('[data-cash-flow-kpis]')).toBeVisible();

    await page.getByRole('link', { name: 'Zrušit filtr' }).click();
    await expect(page.locator('[data-cash-flow-table]')).toBeVisible();
  });
});
