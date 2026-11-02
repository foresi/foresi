// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { REMINDERS_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1440, height: 960 };
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';
const SIZES = [['desktop', DESKTOP], ['phone', PHONE]] as const;

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

function row(page: Page, number: string): Locator {
  return page.locator('tbody tr').filter({ has: page.getByRole('button', { name: `Další akce pro ${number}`, exact: true }) });
}

// The dialog is laid out and its first frame painted, so a screenshot shows it.
async function settled(dialog: Locator): Promise<void> {
  await expect(dialog).toBeVisible();
  await dialog.page().evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

// From the row's menu button to the reminder item, with the keyboard only.
async function reminderByKeyboard(page: Page, number: string): Promise<void> {
  const menu = row(page, number).getByRole('button', { name: /Další akce/ });
  await menu.focus();
  await page.keyboard.press('Enter');
  const item = row(page, number).getByRole('menuitem', { name: 'Poslat upomínku', exact: true });
  for (let presses = 0; presses < 10 && !(await item.evaluate((element) => element === document.activeElement)); presses++) {
    await page.keyboard.press('ArrowDown');
  }
  await expect(item).toBeFocused();
  await page.keyboard.press('Enter');
}

test.use({ storageState: REMINDERS_OWNER_STATE });

test.describe.serial('reminders of overdue invoices', () => {
  test('the list counts the reminders of an overdue row and offers one by hand @a11y @layout', async ({ page }) => {
    for (const [name, size] of SIZES) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      if (name === 'desktop') {
        const count = row(page, 'R0001').locator('[data-icon-cell="bell-ring"]');
        await expect(count).toHaveText('2 upomínky');
        await expect(count).toHaveAttribute('title', /^Poslední upomínka \d{1,2}\.\s\d{1,2}\.\s\d{4} · Odesláno$/);
        await expect(row(page, 'R0002').locator('[data-icon-cell="bell-ring"]')).toHaveCount(0);
      } else {
        await expect(row(page, 'R0001').locator('[data-row-secondary]')).toContainText('2 upomínky');
      }
      await row(page, 'R0003').getByRole('button', { name: /Další akce/ }).click();
      await expect(row(page, 'R0003').getByRole('menuitem', { name: 'Poslat upomínku' })).toHaveCount(0);
      await page.keyboard.press('Escape');
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/reminder-list-${name}.png`, fullPage: true });
    }
  });

  test('a reminder by hand opens over the list with the reminder text and Send focused @a11y @layout', async ({ page }) => {
    for (const [name, size] of SIZES) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      await reminderByKeyboard(page, 'S0001');
      const dialog = page.getByRole('dialog', { name: 'Upomínka 1 k faktuře S0001' });
      await settled(dialog);

      await expect(page).toHaveURL(/\/invoices$/);
      await expect(dialog.locator('[data-reminder-intro]')).toContainText('16 dní po splatnosti, upomínka 1 z nejvýš 10.');
      await expect(dialog.locator('[data-send-preview-subject]')).toHaveText('Upomínka: faktura S0001');
      await expect(dialog.getByRole('checkbox', { name: 'billing@example.com' })).toBeChecked();
      await expect(dialog.getByRole('button', { name: 'Odeslat upomínku' })).toBeFocused();
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/reminder-dialog-${name}.png` });

      // Nothing changed: Escape closes and the focus goes back to the row's menu.
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(row(page, 'S0001').getByRole('button', { name: /Další akce/ })).toBeFocused();
    }
  });

  test('Enter queues the reminder and the history names its step @a11y @layout', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/invoices');
    await reminderByKeyboard(page, 'S0001');
    const dialog = page.getByRole('dialog', { name: 'Upomínka 1 k faktuře S0001' });
    await settled(dialog);
    await expect(dialog.getByRole('button', { name: 'Odeslat upomínku' })).toBeFocused();

    await page.keyboard.press('Enter');

    await expect(dialog).toBeHidden();
    await expect(page.getByRole('status').filter({ hasText: 'Upomínka 1 k faktuře S0001 je ve frontě k odeslání na billing@example.com.' })).toBeVisible();
    await expect(row(page, 'S0001').locator('[data-icon-cell="bell-ring"]')).toHaveText('1 upomínka');

    for (const [name, size] of SIZES) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      await row(page, 'S0001').getByRole('button', { name: /Další akce/ }).click();
      await row(page, 'S0001').getByRole('menuitem', { name: 'Historie', exact: true }).click();
      const reminder = page.locator('[data-send-entry="bell-ring"]');
      await expect(reminder).toHaveCount(1);
      await expect(reminder).toContainText('upomínka 1 na billing@example.com');
      await expect(reminder.getByRole('link', { name: 'Poslat další upomínku' })).toHaveAttribute('href', /\/remind$/);
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/reminder-history-${name}.png`, fullPage: true });
    }
  });

  test('the send log tells reminders from invoices and filters them @a11y @layout', async ({ page }) => {
    for (const [name, size] of SIZES) {
      await page.setViewportSize(size);
      await page.goto('/invoices/sent');
      await page.locator('#send-purpose').selectOption('reminder');
      await page.getByRole('button', { name: 'Filtrovat' }).click();
      await expect(page).toHaveURL(/purpose=reminder/);
      await expect(page.locator('tbody tr')).toHaveCount(3);
      if (name === 'desktop') {
        await expect(page.locator('tbody tr').first().locator('[data-icon-cell="bell-ring"]')).toHaveText(/^Upomínka \d$/);
      } else {
        await expect(page.locator('tbody tr').first().locator('[data-row-secondary]')).toContainText(/Upomínka \d/);
      }
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/reminder-log-${name}.png`, fullPage: true });
    }
  });

  test('the mail settings preview names each invoice with its dates @a11y @layout', async ({ page }) => {
    for (const firm of ['Example Reminding s.r.o.', 'Example Manual s.r.o.']) {
      for (const [name, size] of SIZES) {
        await page.setViewportSize(size);
        await page.goto('/settings/firms');
        await page.getByRole('link', { name: firm, exact: true }).click();
        await page.getByRole('navigation', { name: 'Části firmy' }).getByRole('link', { name: 'E-maily' }).click();
        const preview = page.locator('#reminders-preview');
        await expect(preview).toBeVisible();
        if (firm === 'Example Reminding s.r.o.') {
          await expect(preview.getByRole('heading', { name: 'Zítra dostanou upomínku' })).toBeVisible();
          await expect(preview.locator('li')).toHaveCount(1);
          await expect(preview.locator('li')).toContainText('R0002');
          await expect(preview.locator('li')).toContainText(/splatnost \d{1,2}\. \d{1,2}\. \d{4}/);
        } else {
          await expect(preview.getByRole('heading', { name: 'Kdybyste upomínky zapnuli dnes' })).toBeVisible();
          await expect(preview.locator('li')).toHaveCount(1);
          await expect(preview.locator('li')).toContainText('S0002');
          await expect(preview.locator('li')).toContainText(/upomínka \d{1,2}\. \d{1,2}\. \d{4}/);
        }
        await preview.scrollIntoViewIfNeeded();
        await expectNoSeriousViolations(page);
        await expectNoSidewaysScroll(page);
        await preview.screenshot({ path: `${SHOTS}/reminder-preview-${firm === 'Example Manual s.r.o.' ? 'off' : 'on'}-${name}.png` });
      }
    }
  });
});
