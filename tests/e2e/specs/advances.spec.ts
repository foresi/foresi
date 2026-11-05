// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { signIn } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1440, height: 960 };
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';
// The tenant of this spec in tests/e2e/seed.php: advance Z920260001 of 1 210 Kč unpaid, and
// Z920260002 paid, waiting for its tax document.
const OWNER = 'advances@example.com';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

function row(page: Page, number: string) {
  return page.locator('tbody tr').filter({ has: page.getByRole('button', { name: `Další akce pro ${number}`, exact: true }) });
}

test.describe.serial('advances and tax documents on payment', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await signIn(page, OWNER);
  });

  test('the advance list and the new advance form @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/advances');
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('Zálohové faktury');
      await expect(page.getByRole('link', { name: /Chybí daňový doklad/ })).toContainText('1');
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/advances-list-${name}.png`, fullPage: true });

      await page.goto('/invoices/new?kind=advance');
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Zálohová faktura Z92026/);
      await expect(page.locator('#taxPointDate')).toHaveCount(0);
      await expect(page.getByLabel('Daňový doklad k platbě')).toBeVisible();
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/advance-form-${name}.png`, fullPage: true });
    }
  });

  test('a paid advance waits for its tax document and is issued from the list @a11y @layout', async ({ page }) => {
    await page.goto('/advances/needs-tax-document');
    await expect(page.locator('[data-shell-notices]')).toContainText('Uhrazená záloha čeká na daňový doklad');
    await expect(page.locator('tbody')).toContainText('Z920260002');
    await expectNoSeriousViolations(page);
    await page.setViewportSize(PHONE);
    await expectNoSidewaysScroll(page);
    await page.setViewportSize(DESKTOP);

    await page.locator('tbody tr').filter({ hasText: 'Z920260002' }).getByRole('button', { name: /Další akce/ }).click();
    await page.getByRole('menuitem', { name: 'Vystavit daňový doklad' }).click();

    await expect(page.getByRole('status')).toContainText('Daňový doklad D820260001 je vystavený.');
    await expect(page.locator('main')).toContainText('Každá uhrazená záloha má svůj daňový doklad.');
    await expect(page.locator('[data-shell-notices]')).toHaveCount(0);
  });

  test('a payment of an advance issues its tax document, and its links show the chain', async ({ page }) => {
    await page.goto('/advances');
    await row(page, 'Z920260001').getByRole('link', { name: 'Uhradit' }).click();
    const dialog = page.getByRole('dialog', { name: /Z920260001/ });
    await dialog.getByRole('button', { name: 'Zaznamenat úhradu' }).click();
    await expect(page.getByRole('status')).toContainText('Z920260001');

    await page.goto('/tax-documents');
    await expect(page.locator('tbody')).toContainText('D820260002');
    await expect(row(page, 'D820260002').getByRole('link', { name: 'Uhradit' })).toHaveCount(0);

    await page.goto('/advances');
    await page.getByRole('link', { name: 'Z920260001', exact: true }).click();
    const links = page.locator('[data-links]');
    await expect(links).toContainText('Vazby');
    await expect(links.getByRole('link', { name: 'D820260002' })).toBeVisible();
    await expect(links).toContainText('Zatím nevyúčtováno');
    await expectNoSeriousViolations(page);
  });

  test('the final invoice deducts the advances down to nothing to pay', async ({ page }) => {
    await page.goto('/invoices/new');
    await page.locator('#customer').fill('Example Advan');
    await page.locator('#customer-list').getByRole('option').first().click();
    await expect(page.locator('[data-customer-summary]')).toBeVisible();
    await page.locator('#lines-0-text').fill('Development');
    await page.locator('#lines-0-price').fill('2000');

    await expect(page.getByLabel('Odečíst zálohu').locator('option')).toHaveCount(2);
    for (const number of ['D820260001', 'D820260002']) {
      const select = page.getByLabel('Odečíst zálohu');
      await select.selectOption((await select.locator('option', { hasText: number }).getAttribute('value')) ?? '');
      await page.getByRole('button', { name: 'Odečíst', exact: true }).click();
    }

    await expect(page.locator('[data-deductions]')).toHaveCount(0);
    await expect(page.locator('tbody[data-deducts]')).toHaveCount(2);
    await expectNoSeriousViolations(page);
    await page.getByRole('button', { name: 'Uložit', exact: true }).click();

    await expect(page).toHaveURL(/\/invoices$/);
    await expect(row(page, '20260001')).toContainText('Uhrazeno zálohami');
  });
});
