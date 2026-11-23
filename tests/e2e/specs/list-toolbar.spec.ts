// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { OWNER_STATE } from '../support/session';

test.use({ storageState: OWNER_STATE });

// Every list has the same toolbar at the end of its tabs, as Shopify Polaris IndexFilters puts its
// search, filter and sort icons on the row of view tabs: a filter icon that opens the filter bar under
// the tabs, and on a list with columns the Columns icon. No separate row of filter and Columns buttons.
const LISTS: { path: string; columns: boolean }[] = [
  { path: '/invoices', columns: true },
  { path: '/invoices/recurring', columns: true },
  { path: '/invoices/sent', columns: true },
  { path: '/contacts', columns: true },
  { path: '/bank', columns: true },
  { path: '/purchasing', columns: true },
  { path: '/catalog/products', columns: true },
  { path: '/sales/deals', columns: true },
  { path: '/projects', columns: true },
  { path: '/my-tasks', columns: true },
  { path: '/time/report', columns: true },
  { path: '/notifications', columns: true },
  { path: '/cash-flow', columns: false },
];

test.describe('list toolbar @layout', () => {
  for (const list of LISTS) {
    test(`${list.path} keeps its filter and columns icons on the row of its tabs`, async ({ page }) => {
      await page.goto(list.path);
      await expect(page.locator('main')).toBeVisible();
      const tools = page.locator('[data-list-tools]');
      // An empty list has neither tabs nor tools; then it has no filter row or Columns button elsewhere either.
      if ((await tools.count()) === 0) {
        await expect(page.locator('[data-filter-bar]:visible')).toHaveCount(0);
        await expect(page.locator('[data-column-picker-open]')).toHaveCount(0);
        return;
      }
      await expect(tools).toHaveCount(1);
      // The tabs of the same row: the nav in the row that holds the tools.
      const tabs = tools.locator('xpath=..').locator('nav').first();

      // Icons, on the row of the tabs, at its end.
      const toggle = tools.locator('[data-filter-toggle]');
      const toggleCount = await toggle.count();
      if (list.columns) {
        await expect(tools.locator('[data-column-picker-open]')).toBeVisible();
        await expect(tools.locator('[data-column-picker-open]')).toHaveAttribute('title', /Sloupce/);
      }
      const toolsBox = await tools.boundingBox();
      const tabsBox = await tabs.boundingBox();
      expect(toolsBox && tabsBox).toBeTruthy();
      if (toolsBox && tabsBox) {
        // The same row: their vertical extents overlap.
        expect(toolsBox.y).toBeLessThan(tabsBox.y + tabsBox.height);
        expect(toolsBox.y + toolsBox.height).toBeGreaterThan(tabsBox.y);
        expect(toolsBox.x).toBeGreaterThanOrEqual(tabsBox.x);
      }

      // A list with nothing to filter (empty) shows no filter icon; one with a bar opens it.
      if (toggleCount === 1 && (await toggle.isVisible())) {
        const bar = page.locator(`#${await toggle.getAttribute('aria-controls')}`);
        if ((await toggle.getAttribute('aria-expanded')) === 'false') {
          await expect(bar).toBeHidden();
          await toggle.click();
        }
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(bar).toBeVisible();
      }
    });
  }
});
