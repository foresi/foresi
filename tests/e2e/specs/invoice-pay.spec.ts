// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { signIn } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1440, height: 960 };
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';
// The tenant of this spec in tests/e2e/seed.php: 20260001 unpaid (1 210 Kč), 20260002 paid in
// part (1 000 of 2 420 Kč), 20260003 paid in full.
const PAYING_OWNER = 'paying@example.com';

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

const status = (page: Page, number: string) => row(page, number).locator('td').nth(5);

test.describe.serial('paying an invoice', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await signIn(page, PAYING_OWNER);
  });

  test('the list shows each payment state and the unpaid tab what is left @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      if (name === 'desktop') {
        await expect(status(page, '20260003')).toHaveText('Uhrazeno');
        await expect(status(page, '20260002')).toHaveText('Částečně uhrazeno · zbývá 1 420,00 Kč');
        await expect(status(page, '20260002').locator('[title]')).toHaveAttribute('title', /^zbývá 1\s420,00\sKč$/);
        await expect(status(page, '20260001')).toHaveText('Vystaveno');
      } else {
        await expect(row(page, '20260002').locator('[data-row-secondary]')).toContainText('Částečně uhrazeno · zbývá 1 420,00 Kč');
      }
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/pay-list-${name}.png`, fullPage: true });
    }
    await page.setViewportSize(DESKTOP);
    await page.goto('/invoices?tab=paid');
    await expect(page.locator('tbody a[data-row-link]')).toHaveText(['20260003']);
  });

  test('the dialog fills in what is left, warns about an overpayment and offers to close a small difference @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      if (name === 'desktop') {
        await row(page, '20260002').getByRole('link', { name: 'Uhradit' }).click();
      } else {
        await row(page, '20260002').getByRole('button', { name: /Další akce/ }).click();
        await row(page, '20260002').getByRole('menuitem', { name: 'Uhradit' }).click();
      }
      const dialog = page.getByRole('dialog', { name: 'Uhradit fakturu 20260002' });
      await expect(dialog).toBeVisible();
      await expect(dialog).toContainText('Zbývá 1 420,00 Kč z 2 420,00 Kč');
      await expect(page.locator('#pay-amount')).toBeFocused();
      await expect(page.locator('#pay-amount')).toHaveValue(/^1\s420,00$/);
      await expect(page.locator('#pay-receivedOn')).toHaveValue(/^\d{4}-\d{2}-\d{2}$/);
      await expect(page.locator('#pay-method')).toHaveValue('bank_transfer');
      await expect(page.locator('[data-settle]')).toBeHidden();
      await expect(page.locator('[data-overpay-warning]')).toBeHidden();
      if (name === 'phone') {
        // Full screen on a phone.
        const box = await dialog.boundingBox();
        expect(box?.width).toBe(PHONE.width);
        expect(box?.height).toBe(PHONE.height);
      }
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/pay-dialog-${name}.png`, fullPage: true });

      await page.locator('#pay-amount').fill('1500');
      await expect(page.locator('[data-overpay-warning]')).toHaveText('Faktura bude přeplacená o 80,00 Kč.');
      await expect(page.locator('[data-settle]')).toBeHidden();
      await page.screenshot({ path: `${SHOTS}/pay-overpay-${name}.png`, fullPage: true });

      await page.locator('#pay-amount').fill('1419,50');
      await expect(page.locator('[data-overpay-warning]')).toBeHidden();
      await expect(page.locator('[data-settle]')).toBeVisible();
      await expect(page.locator('[data-settle]')).toContainText('Odepíše se 0,50 Kč, aby byla faktura uhrazená přesně.');
      await page.screenshot({ path: `${SHOTS}/pay-settle-${name}.png`, fullPage: true });

      await page.locator('#pay-amount').fill('1420,30');
      await page.getByLabel('Uzavřít fakturu a rozdíl odepsat').check();
      await expect(page.locator('[data-overpay-warning]')).toBeHidden();
      await dialog.getByRole('button', { name: 'Zrušit' }).click();
      await dialog.getByRole('button', { name: 'Zahodit' }).click();
      await expect(dialog).toBeHidden();
    }
  });

  test('Enter records the payment and the row shows it paid', async ({ page }) => {
    await page.goto('/invoices');
    // Keyboard only: the row's Pay opens the dialog, the amount is ready, Enter records it.
    await row(page, '20260001').getByRole('link', { name: 'Uhradit' }).focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Uhradit fakturu 20260001' });
    await expect(dialog).toBeVisible();
    await expect(page.locator('#pay-amount')).toBeFocused();
    await expect(page.locator('#pay-amount')).toHaveValue(/^1\s210,00$/);
    await page.keyboard.press('Enter');

    await expect(dialog).toBeHidden();
    await expect(page.getByRole('status').filter({ hasText: 'Úhrada 1 210,00 Kč k faktuře 20260001 je zaznamenaná.' })).toBeVisible();
    await expect(status(page, '20260001')).toHaveText('Uhrazeno');
    await expect(row(page, '20260001').getByRole('link', { name: 'Uhradit' })).toHaveCount(0);
  });

  test('a refusal keeps what was typed and puts the focus on what to fix @a11y', async ({ page }) => {
    await page.goto('/invoices');
    await row(page, '20260002').getByRole('link', { name: 'Uhradit' }).click();
    await page.locator('#pay-amount').fill('0');
    await page.locator('#pay-note').fill('Hotově na pobočce');
    await page.getByRole('button', { name: 'Zaznamenat úhradu' }).click();

    await expect(page.locator('#pay-amount-error')).toHaveText('Zadejte částku větší než nula.');
    await expect(page.locator('#pay-amount')).toBeFocused();
    await expect(page.locator('#pay-note')).toHaveValue('Hotově na pobočce');
    await expectNoSeriousViolations(page);
    await page.screenshot({ path: `${SHOTS}/pay-refused-desktop.png`, fullPage: true });
  });

  test('the invoice lists its payments and removes one after asking @a11y @layout', async ({ page }) => {
    await page.goto('/invoices');
    await row(page, '20260002').locator('a[data-row-link]').click();
    const panel = page.locator('[data-payments]');
    await expect(panel).toContainText('1 000,00 Kč');
    await expect(panel).toContainText('Převodem');
    await expect(panel.locator('[data-payments-remaining]')).toHaveText('1 420,00 Kč');
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await panel.scrollIntoViewIfNeeded();
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/pay-panel-${name}.png`, fullPage: true });
    }
    await page.setViewportSize(DESKTOP);

    await panel.getByRole('button', { name: /^Odebrat úhradu 1 000,00 Kč/ }).click();
    const confirm = page.getByRole('alertdialog', { name: 'Odebrat úhradu 1 000,00 Kč?' });
    await expect(confirm).toContainText('Faktura bude o tuto částku znovu neuhrazená.');
    await expect(confirm.getByRole('button', { name: 'Ponechat' })).toBeFocused();
    await page.screenshot({ path: `${SHOTS}/pay-remove-desktop.png`, fullPage: true });
    await confirm.getByRole('button', { name: 'Odebrat úhradu' }).click();

    await expect(page.getByRole('status').filter({ hasText: 'Úhrada 1 000,00 Kč je odebraná.' })).toBeVisible();
    await expect(panel).toContainText('Zatím žádné úhrady.');
    await expect(page.locator('header h1 + span')).toHaveText('Vystaveno');
  });
});
