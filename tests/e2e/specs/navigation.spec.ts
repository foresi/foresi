// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '../support/test';
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
  if ((await toggle.isVisible()) && (await toggle.getAttribute('aria-expanded')) !== 'true') {
    await toggle.click();
    await expect(page.locator('#sidebar')).toBeVisible();
  }
}

function isPhone(): boolean {
  return test.info().project.name === 'mobile';
}

// Opens an area: from the rail on a wide screen, from the drawer on a phone.
async function openArea(page: Page, label: string): Promise<void> {
  if (isPhone()) {
    await openMenuOnPhone(page);
    await page.locator('#sidebar').getByRole('link', { name: label, exact: true }).first().click();
  } else {
    await page.locator('#rail').getByRole('link', { name: label, exact: true }).click();
  }
  await expect(page.locator(`#sidebar [data-shown] h2`)).toHaveText(label);
}

async function shot(page: Page, name: string): Promise<void> {
  if (SHOTS) {
    const project = test.info().project.name;
    await page.screenshot({ path: `${SHOTS}/${project}-${name}.png`, fullPage: false });
  }
}

test.describe('navigation', () => {
  test.use({ storageState: INVOICING_OWNER_STATE });

  test('shows the areas in the rail and the sections of the current area @layout', async ({ page }) => {
    await page.goto('/');
    await openMenuOnPhone(page);
    const sidebar = page.locator('#sidebar');

    // Only areas that hold something are shown, in the fixed order, and Settings is last.
    const areas = await sidebar.locator('section[data-nav-area]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-nav-area')));
    expect(areas).toEqual(['overview', 'sales', 'purchasing', 'money', 'contacts', 'projects', 'settings']);
    if (!isPhone()) {
      const rail = page.locator('#rail');
      await expect(rail.locator('a[data-nav-area]')).toHaveText([/Přehled/, 'Prodej', 'Nákup', 'Peníze', 'Kontakty', 'Projekty', 'Nastavení']);
      await expect(rail.locator('a[aria-current="true"]')).toHaveAttribute('data-nav-area', 'overview');
      await expect(rail.getByRole('button', { name: 'Hledat' })).toBeVisible();
      await expect(sidebar.getByRole('heading', { level: 2, name: 'Přehled' })).toBeVisible();
    }
    // The inbox sits under the start page: where a person begins, not a part of the projects.
    await expect(sidebar.locator('[data-nav-area="overview"] [data-nav-section]').getByRole('link')).toHaveText([/Přehled/, /Schránka/, /Oznámení/]);
    await shot(page, 'sidebar');

    for (const [label, area, links] of [
      ['Prodej', 'sales', ['Obchody', 'Dokumenty', /Produkty/, /Faktury/, /Zálohové faktury/, /Daňové doklady/, /Opravné doklady/, /Pravidelné faktury/, /Odeslané e-maily/]],
      ['Nákup', 'purchasing', [/Náklady/]],
      ['Kontakty', 'contacts', [/Adresář/]],
      ['Projekty', 'projects', [/Projekty/, /Moje úkoly/, /Čas/]],
      ['Nastavení', 'settings', ['Firma', 'Lidé', 'Štítky', 'Dokumenty', 'Přijaté doklady', 'Aktivity', 'Obchody', 'Import a export', 'API a MCP', 'Webhooky']],
    ] as const) {
      await openArea(page, label);
      await openMenuOnPhone(page);
      const shown = sidebar.locator(`[data-nav-area="${area}"][data-shown]`);
      await expect(shown.locator('[data-nav-section]').getByRole('link')).toHaveText([...links]);
      await expect(shown.locator('[aria-current="page"]')).toHaveCount(1);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    }
    await expectNoSidewaysScroll(page);
  });

  test('keeps the rail and the sidebar across visits and moves only the content @layout', async ({ page }) => {
    test.skip(isPhone(), 'The rail is part of the drawer on a phone.');
    await page.goto('/invoices');
    await page.evaluate(() => {
      (window as unknown as { keptRail: Element | null }).keptRail = document.getElementById('rail');
    });

    await page.locator('#rail').getByRole('link', { name: 'Kontakty' }).click();
    await expect(page).toHaveURL(/\/contacts$/);
    await expect(page.locator('#rail a[aria-current="true"]')).toHaveAttribute('data-nav-area', 'contacts');
    await expect(page.locator('#sidebar [data-shown]')).toHaveAttribute('data-nav-area', 'contacts');
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Adresář/);
    expect(await page.evaluate(() => (window as unknown as { keptRail: Element | null }).keptRail === document.getElementById('rail'))).toBe(true);
    expect(await page.locator('main').evaluate((main) => getComputedStyle(main).viewTransitionName)).toBe('content');
    expect(await page.locator('#rail').evaluate((rail) => getComputedStyle(rail).viewTransitionName)).toBe('none');
    await expectNoSeriousViolations(page);
  });

  test('keeps the section marked on the pages that belong to it @layout', async ({ page }) => {
    await page.goto('/invoices');
    await openMenuOnPhone(page);
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Faktury/);
    await expect(page.locator('#sidebar [data-shown]')).toHaveAttribute('data-nav-area', 'sales');

    await page.goto('/imports');
    await openMenuOnPhone(page);
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Import a export/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await shot(page, 'import-and-export');
  });

  test('opens the settings of a firm as tabs on the firm page @layout @a11y', async ({ page }) => {
    await page.goto('/settings/firm');
    await openMenuOnPhone(page);
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Firma/);
    // On a phone the menu stays open over the page; load it again to reach the tabs.
    await page.goto('/settings/firm');

    const tabs = page.getByRole('navigation', { name: 'Části firmy' });
    await expect(tabs.getByRole('link')).toHaveText(['Údaje', 'DPH', 'Fakturace', 'E-maily', 'Banka']);
    await expect(tabs.locator('[aria-current="page"]')).toHaveText('Údaje');
    await expect(page).toHaveURL(/\/settings\/firm$/);
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
    await shot(page, 'firm-details-tab');

    // The VAT status over time has its own tab, with the change added in the page header.
    await tabs.getByRole('link', { name: 'DPH' }).click();
    await expect(page).toHaveURL(/\/settings\/firm\/vat$/);
    await expect(tabs.locator('[aria-current="page"]')).toHaveText('DPH');
    await expect(page.locator('#firm-vat-add')).toHaveCount(1);
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);

    await tabs.getByRole('link', { name: 'Fakturace' }).click();
    await expect(page).toHaveURL(/\/settings\/firm\/invoicing$/);
    await expect(tabs.locator('[aria-current="page"]')).toHaveText('Fakturace');
    // The series are inside the invoicing tab and keep their address.
    await expect(page.getByRole('heading', { name: 'Číselné řady' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Nová číselná řada/ })).toBeVisible();
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
    await shot(page, 'firm-invoicing-tab');

    await openMenuOnPhone(page);
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Firma/);
  });
});
