// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '../support/test';
import { OWNER_STATE, signIn } from '../support/session';

test.use({ storageState: OWNER_STATE });

// Every list has one toolbar row, as Shopify Polaris IndexFilters puts search and filter on the row
// of view tabs and Linear, Notion and Attio keep a view's display options in one settings menu: the
// view tabs on the left, then the search field, the Filter icon, which opens the filter sheet at
// once, and the Settings icon, the sheet of the list's views, quick filters and columns.
// Under the tabs stand the view's quick filters as solid chips; there is no "+ Filtr" button.
const LISTS: string[] = [
  '/invoices',
  '/invoices/recurring',
  '/invoices/sent',
  '/contacts',
  '/bank',
  '/purchasing',
  '/catalog/products',
  '/sales/deals',
  '/projects',
  '/tasks',
  '/my-tasks',
  '/time/report',
  '/notifications',
  '/cash-flow',
  '/documents',
];

const phone = (page: Page): boolean => (page.viewportSize()?.width ?? 1440) < 640;

test.describe('list toolbar @layout', () => {
  for (const path of LISTS) {
    test(`${path} has the search field, the Filter and the Settings icons in this order on the row of its tabs`, async ({ page }) => {
      await page.goto(path);
      await expect(page.locator('main')).toBeVisible();
      const tools = page.locator('[data-list-tools]');
      // An empty list has no tools; then it has no filter row or Settings button elsewhere either.
      if ((await tools.count()) === 0) {
        await expect(page.locator('[data-filter-bar]:visible')).toHaveCount(0);
        await expect(page.locator('[data-column-picker-open]')).toHaveCount(0);
        return;
      }
      await expect(tools).toHaveCount(1);

      const settings = tools.locator('[data-column-picker-open]');
      await expect(settings).toBeVisible();
      await expect(settings).toHaveAccessibleName(/Nastavení seznamu/);

      // The search is a field on a desktop and an icon that opens it on a phone.
      const field = tools.locator('[data-list-search] input[type=search]');
      const searchIcon = tools.locator('[data-list-search-open]');
      if (phone(page)) {
        await expect(field).toBeHidden();
        await expect(searchIcon).toBeVisible();
      } else {
        await expect(field).toBeVisible();
        await expect(searchIcon).toBeHidden();
        expect((await field.boundingBox())?.width).toBe(256);
      }

      const order = await tools
        .locator('[data-list-search]:visible, [data-list-search-open]:visible, [data-filter-toggle]:visible, [data-column-picker-open]')
        .evaluateAll((nodes) => nodes.map((node) => (node.hasAttribute('data-filter-toggle') ? 'filter' : node.hasAttribute('data-column-picker-open') ? 'settings' : 'search')));
      // A list with nothing to filter yet (no records) hides its Filter icon, never moves it.
      const filter = tools.locator('[data-filter-toggle]');
      const filters = await filter.isVisible();
      expect(order).toEqual(filters ? ['search', 'filter', 'settings'] : ['search', 'settings']);

      // The tabs, when the list has any, on the same row as the tools.
      const tabs = page.locator('[data-list-toolbar] nav').first();
      if ((await tabs.count()) === 1) {
        const toolsBox = await tools.boundingBox();
        const tabsBox = await tabs.boundingBox();
        if (toolsBox && tabsBox) {
          expect(toolsBox.y).toBeLessThan(tabsBox.y + tabsBox.height);
          expect(toolsBox.y + toolsBox.height).toBeGreaterThan(tabsBox.y);
        }
      }

      // No menu of more views on the row and no "+ Filtr" in the filter row.
      await expect(page.locator('[data-list-toolbar]').getByRole('button', { name: /Další pohledy/ })).toHaveCount(0);
      await expect(page.locator('[data-filter-bar]').getByRole('button', { name: /^\+ ?Filtr$/ })).toHaveCount(0);

      // The Filter icon opens the filter sheet in one click.
      if (filters) {
        await filter.click();
        await expect(page.getByRole('dialog', { name: 'Filtry' })).toBeVisible();
      }
    });
  }

  test('the invoices pin their tax point and customer as quick filters and search on the server', async ({ page }) => {
    // The owner of OWNER_STATE has no invoices; the cash flow world has one (tests/e2e/seed/cash-flow.php).
    await page.context().clearCookies();
    await signIn(page, 'cash-flow@example.com');
    await page.goto('/invoices');
    const bar = page.locator('#invoice-filters');
    // A tax document counts its month by the tax point (InvoiceLists::monthField).
    const date = bar.locator('[data-quick-filter="tax_point_date"]');
    await expect(date).toBeVisible();
    await expect(bar.locator('[data-quick-filter="customer"]')).toBeVisible();

    // The date chip is a menu of the periods and the months with documents; a pick sets its condition.
    await date.locator('summary').click();
    await date.getByRole('menuitemradio', { name: 'letos', exact: true }).click();
    await expect(page).toHaveURL(/[?&]f=/);
    const set = page.locator('#invoice-filters [data-quick-filter="tax_point_date"][data-set]');
    await expect(set).toContainText('DUZP: letos');
    // The view now differs from its saved one: the row offers to save it, and to clear it all.
    await expect(page.locator('#invoice-filters [data-view-save-as-button]')).toBeVisible();
    await expect(page.locator('#invoice-filters [data-filter-clear-all]')).toBeVisible();

    // The search sends on Enter and keeps the filter; "Zrušit vše" clears both.
    if (phone(page)) {
      await page.locator('[data-list-search-open]').click();
    }
    const search = page.locator('[data-list-search] input[type=search]');
    await search.fill('example');
    await search.press('Enter');
    await expect(page).toHaveURL(/[?&]q=example/);
    await expect(page).toHaveURL(/[?&]f=/);
    await page.locator('#invoice-filters [data-filter-clear-all]').click();
    await expect(page).not.toHaveURL(/[?&](q|f)=/);
  });

  test('the Settings sheet pins quick filters, which the row then shows and the view keeps', async ({ page }) => {
    await page.goto('/contacts');
    await page.locator('[data-column-picker-open]').click();
    const sheet = page.getByRole('dialog', { name: 'Nastavení seznamu' });
    await expect(sheet.locator('[data-settings-views]')).toBeVisible();
    await expect(sheet.getByRole('group', { name: 'Rychlé filtry' })).toBeVisible();
    await expect(sheet.getByRole('group', { name: 'Sloupce' })).toBeVisible();
    await expect(sheet.getByRole('group', { name: 'Hustota řádků' })).toHaveCount(0);
    await expect(sheet.getByRole('link', { name: 'Obnovit výchozí' })).toBeVisible();

    // Every filter field of the list is in one list, the pinned first: unpin the second default
    // and pin "Archivováno" in its place.
    const quick = sheet.locator('[data-column-picker-quick]');
    await quick.locator('[data-column-picker-target="pinRow"] input[type=checkbox]').nth(1).uncheck();
    await quick.locator('[data-column-picker-target="pinRow"][data-key="archived"] input[type=checkbox]').check();
    await sheet.getByRole('button', { name: 'Použít' }).click();

    await expect(page).toHaveURL(/[?&]pins=roles(%2C|,)archived/);
    await expect(page.locator('#contact-filters [data-quick-filter="archived"]')).toBeVisible();
    await expect(page.locator('#contact-filters [data-quick-filter="kind"]')).toHaveCount(0);
    await expect(page.locator('[data-column-picker-modified]')).toBeVisible();

    // The person's display is remembered (D-display-3); Reset forgets it, for the next run too.
    await page.locator('[data-column-picker-open]').click();
    await page.getByRole('dialog', { name: 'Nastavení seznamu' }).getByRole('link', { name: 'Obnovit výchozí' }).click();
    await expect(page).not.toHaveURL(/[?&]pins=/);
    await expect(page.locator('[data-column-picker-modified]')).toHaveCount(0);
  });
});
