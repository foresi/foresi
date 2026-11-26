// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '../support/test';
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

    // Certainty is a field of the filter sheet, sent with Apply as a condition in f=, as on the list pages.
    // The filter icon at the end of the tabs opens the filter sheet at once (Shopify Polaris IndexFilters).
    await page.locator('[data-filter-toggle]').click();
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

    // The empty state clears the filter; the chip of the condition has its own ✕ ("Zrušit filtr Zdroj").
    await page.locator('[data-cash-flow-table-empty]').getByRole('link', { name: 'Zrušit filtr', exact: true }).click();
    await expect(page.locator('[data-cash-flow-table]')).toBeVisible();
  });

  // Twelve months are wider than the card: the table scrolls in its own box and the page never sideways,
  // also with the screen-reader texts of the empty cells far to the right.
  for (const width of [1440, 375]) {
    test(`the 12 months table scrolls in its box, not the page, at ${width} px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/cash-flow');
      const table = page.locator('[data-cash-flow-table]');
      await expect(table).toBeVisible();

      const box = await table.evaluate((element) => ({ scroll: element.parentElement!.scrollWidth, client: element.parentElement!.clientWidth }));
      expect(box.scroll).toBeGreaterThan(box.client);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow).toBeLessThanOrEqual(0);
    });
  }
});
