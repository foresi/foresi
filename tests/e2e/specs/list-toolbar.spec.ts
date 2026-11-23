// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { OWNER_STATE, signIn } from '../support/session';

test.use({ storageState: OWNER_STATE });

// Every list has the same toolbar at the end of its tabs, as Shopify Polaris IndexFilters puts its
// search, filter and sort icons on the row of view tabs and Linear its display options: Search (on a
// list that searches), Filter, which opens the filter sheet at once, and Display, the sheet of the
// view's quick filters, columns and density. Under the tabs stand the view's quick filters as chips.
const LISTS: { path: string; search: boolean }[] = [
  { path: '/invoices', search: true },
  { path: '/invoices/recurring', search: false },
  { path: '/invoices/sent', search: false },
  { path: '/contacts', search: true },
  { path: '/bank', search: true },
  { path: '/purchasing', search: true },
  { path: '/catalog/products', search: false },
  { path: '/sales/deals', search: false },
  { path: '/projects', search: false },
  { path: '/my-tasks', search: false },
  { path: '/time/report', search: false },
  { path: '/notifications', search: false },
  { path: '/cash-flow', search: false },
  { path: '/documents', search: false },
];

const phone = (page: Page): boolean => (page.viewportSize()?.width ?? 1440) < 640;

test.describe('list toolbar @layout', () => {
  for (const list of LISTS) {
    test(`${list.path} has the search, filter and Display icons in this order at the end of its tabs`, async ({ page }) => {
      await page.goto(list.path);
      await expect(page.locator('main')).toBeVisible();
      const tools = page.locator('[data-list-tools]');
      // An empty list has no tools; then it has no filter row or Display button elsewhere either.
      if ((await tools.count()) === 0) {
        await expect(page.locator('[data-filter-bar]:visible')).toHaveCount(0);
        await expect(page.locator('[data-column-picker-open]')).toHaveCount(0);
        return;
      }
      await expect(tools).toHaveCount(1);

      const display = tools.locator('[data-column-picker-open]');
      await expect(display).toBeVisible();
      await expect(display).toHaveAttribute('title', /Zobrazení/);
      const icons = tools.locator('[data-search-toggle]:visible, [data-filter-toggle]:visible, [data-column-picker-open]');
      const order = await icons.evaluateAll((nodes) => nodes.map((node) => (node.hasAttribute('data-search-toggle') ? 'search' : node.hasAttribute('data-filter-toggle') ? 'filter' : 'display')));
      expect(order.at(-1)).toBe('display');
      if (order.includes('search')) {
        expect(order.indexOf('search')).toBeLessThan(order.indexOf('filter'));
      }
      expect(order.includes('search')).toBe(list.search && order.includes('filter'));

      // The tabs, when the list has any, on the same row as the icons.
      const tabs = tools.locator('xpath=..').locator('nav').first();
      if ((await tabs.count()) === 1) {
        const toolsBox = await tools.boundingBox();
        const tabsBox = await tabs.boundingBox();
        if (toolsBox && tabsBox) {
          expect(toolsBox.y).toBeLessThan(tabsBox.y + tabsBox.height);
          expect(toolsBox.y + toolsBox.height).toBeGreaterThan(tabsBox.y);
        }
      }

      // The filter icon opens the filter sheet in one click.
      const filter = tools.locator('[data-filter-toggle]');
      if ((await filter.count()) === 1 && (await filter.isVisible())) {
        await filter.click();
        await expect(page.getByRole('dialog', { name: 'Filtry' })).toBeVisible();
      }
    });
  }

  test('the invoices pin their tax point and customer as quick filters under the tabs', async ({ page }) => {
    // The owner of OWNER_STATE has no invoices; the cash flow world has one (tests/e2e/seed/cash-flow.php).
    await page.context().clearCookies();
    await signIn(page, 'cash-flow@example.com');
    await page.goto('/invoices');
    const bar = page.locator('#invoice-filters');
    if (phone(page)) {
      // On a phone the row is the search and "Filtry (n)", which opens the same sheet.
      await expect(bar.locator('[data-filter-open]')).toBeVisible();
      return;
    }
    // A tax document counts its month by the tax point (InvoiceLists::monthField).
    const date = bar.locator('[data-quick-filter="tax_point_date"]');
    await expect(date).toBeVisible();
    await expect(bar.locator('[data-quick-filter="customer"]')).toBeVisible();
    // The search waits for its icon.
    const search = bar.locator('[data-filter-search]').getByRole('searchbox');
    await expect(search).toBeHidden();
    await page.locator('[data-search-toggle]').click();
    await expect(search).toBeFocused();

    // The date chip is a menu of the periods and the months with documents; a pick sets its condition.
    await date.getByRole('button', { name: 'DUZP' }).click();
    await date.getByRole('menuitemradio', { name: 'letos', exact: true }).click();
    await expect(page).toHaveURL(/[?&]f=/);
    await expect(page.locator('#invoice-filters [data-quick-filter="tax_point_date"][data-set]')).toContainText('letos');
    // The view now differs from its saved one: the row offers to save it.
    await expect(page.locator('#invoice-filters [data-view-save-as-button]')).toBeVisible();
  });

  test('the Display sheet pins quick filters, which the row then shows and the view keeps', async ({ page }) => {
    await page.goto('/contacts');
    await page.locator('[data-column-picker-open]').click();
    const sheet = page.getByRole('dialog', { name: 'Zobrazení' });
    await expect(sheet.getByRole('group', { name: 'Rychlé filtry' })).toBeVisible();
    await expect(sheet.getByRole('group', { name: 'Sloupce' })).toBeVisible();
    await expect(sheet.getByRole('group', { name: 'Hustota řádků' })).toBeVisible();
    await expect(sheet.getByRole('link', { name: 'Obnovit výchozí' })).toBeVisible();

    // Unpin the second default and pin "Archivováno" in its place.
    const quick = sheet.locator('[data-column-picker-quick]');
    await quick.locator('[data-column-picker-target="pinRow"] input[type=checkbox]').nth(1).uncheck();
    await quick.locator('[data-column-picker-quick-add] summary').click();
    await quick.locator('[data-column-picker-target="pinOption"][data-key="archived"] input').check();
    await sheet.getByRole('button', { name: 'Použít' }).click();

    await expect(page).toHaveURL(/[?&]pins=roles%2Carchived|[?&]pins=roles,archived/);
    if (!phone(page)) {
      await expect(page.locator('#contact-filters [data-quick-filter="archived"]')).toBeVisible();
      await expect(page.locator('#contact-filters [data-quick-filter="kind"]')).toHaveCount(0);
    }
    await expect(page.locator('[data-column-picker-modified]')).toBeVisible();

    // The person's display is remembered (D-display-3); Reset forgets it, for the next run too.
    await page.locator('[data-column-picker-open]').click();
    await page.getByRole('dialog', { name: 'Zobrazení' }).getByRole('link', { name: 'Obnovit výchozí' }).click();
    await expect(page).not.toHaveURL(/[?&]pins=/);
    await expect(page.locator('[data-column-picker-modified]')).toHaveCount(0);
  });
});
