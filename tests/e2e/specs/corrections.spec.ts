// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { signIn } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1440, height: 960 };
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';
// The tenant of this spec in tests/e2e/seed.php: invoice 20260001 of 1 210 Kč unpaid, and
// 20260002 of 1 210 Kč paid in full.
const OWNER = 'corrections@example.com';

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

// The firms' day, in Prague as the server keeps it.
function today(): string {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Prague' }).format(new Date());
}

// From the invoice list row menu to the corrective document form, with its reason and decisive date.
async function openCorrection(page: Page, number: string): Promise<void> {
  await page.goto('/invoices');
  await row(page, number).getByRole('button', { name: `Další akce pro ${number}`, exact: true }).click();
  await page.getByRole('menuitem', { name: 'Vystavit opravný doklad' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Opravný daňový doklad/);
  await expect(page.locator('[data-corrects]')).toContainText(`Opravuje doklad č. ${number}`);
  await page.locator('#correctionReason').fill('Returned goods');
  await page.locator('#decisiveDate').fill(today());
}

test.describe.serial('corrective tax documents', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await signIn(page, OWNER);
  });

  test('a credit note from the row menu leaves the unpaid invoice less to pay @a11y @layout', async ({ page }) => {
    await openCorrection(page, '20260001');
    // The lines come negated: crediting 400 of the 1 000 leaves 726 Kč of 1 210 Kč to pay.
    await page.locator('#lines-0-price').fill('400');
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/correction-form-${name}.png`, fullPage: true });
    }
    await page.setViewportSize(DESKTOP);
    await page.getByRole('button', { name: 'Uložit', exact: true }).click();

    await expect(page.locator('main').getByRole('status')).toBeVisible();
    await page.goto('/invoices');
    await page.getByRole('link', { name: '20260001', exact: true }).click();
    const payments = page.locator('[data-payments]');
    await expect(payments).toContainText('Zbývá uhradit');
    await expect(payments).toContainText('726,00');
  });

  test('a credit note on the paid invoice leaves it to refund, and the refund is recorded by hand', async ({ page }) => {
    await openCorrection(page, '20260002');
    await page.getByRole('button', { name: 'Uložit', exact: true }).click();
    await expect(page.locator('main').getByRole('status')).toBeVisible();

    await page.goto('/invoices?tab=to_refund');
    await expect(row(page, '20260002')).toContainText('K vrácení');
    await page.getByRole('link', { name: '20260002', exact: true }).click();
    await page.locator('#invoice-refund').click();
    const dialog = page.getByRole('dialog', { name: /20260002/ });
    await expect(dialog.locator('[data-refund-summary]')).toContainText('1 210,00');
    await expectNoSeriousViolations(page);
    await dialog.getByRole('button', { name: 'Zaznamenat vrácení' }).click();

    await expect(dialog).toHaveCount(0);
    // The payment and the refund, an outgoing payment, side by side.
    await expect(page.locator('[data-payments] [data-payment]')).toHaveCount(2);
    await expect(page.locator('[data-payments]')).not.toContainText('K vrácení');
    await page.goto('/invoices');
    await expect(row(page, '20260002')).not.toContainText('K vrácení');
  });

  test('the corrections list shows both credit notes with their PDF @a11y', async ({ page }) => {
    await page.goto('/corrections');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Opravné doklady');
    await expect(page.locator('[data-table-footer]')).toContainText('2 opravné doklady');
    await expectNoSeriousViolations(page);

    const first = page.locator('tbody tr').first();
    await expect(first.getByRole('button', { name: /^Další akce pro O7/ })).toBeVisible();
    await first.getByRole('button', { name: /^Další akce pro O7/ }).click();
    const href = (await first.getByRole('menuitem', { name: 'PDF', exact: true }).getAttribute('href')) ?? '';
    expect(href).toMatch(/\/invoices\/[0-9a-f-]+\/pdf$/);
    const response = await page.request.get(href);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/pdf');
  });
});
