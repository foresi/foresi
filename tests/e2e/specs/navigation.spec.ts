// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { INVOICING_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
// Set it to a folder to save screenshots of the screens for review. They are not baselines.
const SHOTS = process.env.NAV_SHOTS;

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

async function openMenuOnPhone(page: Page): Promise<void> {
  const toggle = page.locator('button[data-sidebar-target="toggle"]');
  if (await toggle.isVisible()) {
    await toggle.click();
    await expect(page.locator('#sidebar')).toBeVisible();
  }
}

async function shot(page: Page, name: string): Promise<void> {
  if (SHOTS) {
    const project = test.info().project.name;
    await page.screenshot({ path: `${SHOTS}/${project}-${name}.png`, fullPage: false });
  }
}

test.describe('navigation', () => {
  test.use({ storageState: INVOICING_OWNER_STATE });

  test('groups the sidebar around documents and settings @layout', async ({ page }) => {
    await page.goto('/');
    await openMenuOnPhone(page);
    const sidebar = page.locator('#sidebar');

    // Only groups that hold something are shown, in the fixed order, and Settings is last.
    const groups = await sidebar.locator('[data-nav-group]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-nav-group')));
    expect(groups).toEqual(['main', 'sales', 'contacts', 'settings']);
    await expect(sidebar.locator('[data-nav-group="sales"]').getByRole('heading', { name: 'Prodej' })).toBeVisible();
    await expect(sidebar.locator('[data-nav-group="sales"]').getByRole('link', { name: 'Faktury' })).toBeVisible();
    await expect(sidebar.locator('[data-nav-group="contacts"]').getByRole('heading', { name: 'Adresář' })).toBeVisible();
    await expect(sidebar.locator('[data-nav-group="contacts"]').getByRole('link', { name: /Adresář/ })).toBeVisible();
    const settings = sidebar.locator('[data-nav-group="settings"]').getByRole('link');
    await expect(settings).toHaveText(['Firmy', 'Import a export']);
    await shot(page, 'sidebar');
  });

  test('keeps the group marked on the pages that belong to it @layout', async ({ page }) => {
    await page.goto('/invoices');
    await openMenuOnPhone(page);
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Faktury/);

    await page.goto('/imports');
    await openMenuOnPhone(page);
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Import a export/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await shot(page, 'import-and-export');
  });

  test('opens the settings of a firm as tabs on the firm page @layout @a11y', async ({ page }) => {
    await page.goto('/settings/firms');
    await openMenuOnPhone(page);
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Firmy/);
    await page.goto('/settings/firms');
    await page.getByRole('link', { name: 'Example Payer s.r.o.' }).first().click();

    const tabs = page.getByRole('navigation', { name: 'Části firmy' });
    await expect(tabs.getByRole('link')).toHaveText(['Údaje', 'Fakturace']);
    await expect(tabs.locator('[aria-current="page"]')).toHaveText('Údaje');
    await expect(page).toHaveURL(/\/settings\/firms\/[0-9a-f-]+\/edit$/);
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
    await shot(page, 'firm-details-tab');

    await tabs.getByRole('link', { name: 'Fakturace' }).click();
    await expect(page).toHaveURL(/\/settings\/firms\/[0-9a-f-]+\/invoicing$/);
    await expect(tabs.locator('[aria-current="page"]')).toHaveText('Fakturace');
    // The series are inside the invoicing tab and keep their address.
    await expect(page.getByRole('heading', { name: 'Číselné řady' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Nová číselná řada/ })).toBeVisible();
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
    await shot(page, 'firm-invoicing-tab');

    await openMenuOnPhone(page);
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Firmy/);
  });
});
