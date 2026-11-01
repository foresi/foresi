// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { INVOICING_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const PHONE = { width: 390, height: 844 };

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const page_ = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(page_).toBeLessThanOrEqual(0);
  const table = await page.locator('table').first().evaluate((element) => {
    const box = element.parentElement as HTMLElement;
    return box.scrollWidth - box.clientWidth;
  });
  expect(table).toBeLessThanOrEqual(0);
}

async function issue(page: Page, description: string, dueDate?: string): Promise<void> {
  await page.goto('/invoices/new');
  await expect(page.locator('form#invoice-form')).toBeVisible();
  const firm = page.locator('select[name=firmId]');
  if (await firm.count() > 0 && (await firm.locator('option:checked').textContent()) !== 'Example Payer s.r.o.') {
    await firm.selectOption({ label: 'Example Payer s.r.o.' });
    await expect(page.locator('select[data-cell=vat]').first()).toBeVisible();
  }
  await page.locator('#customer').fill('Example Dome');
  await page.getByRole('option', { name: 'Example Domestic a.s.' }).click();
  await expect(page.locator('[data-customer-summary]')).toBeVisible();
  await page.locator('#description').fill(description);
  await page.locator('#lines-0-text').fill('Práce');
  await page.locator('#lines-0-price').fill('500');
  if (dueDate !== undefined) {
    await page.locator('#issueDate').fill('2026-01-05');
    await page.locator('#issueDate').dispatchEvent('change');
    await page.locator('#taxPointDate').fill('2026-01-05');
    await page.locator('#dueDate').fill(dueDate);
  }
  await page.locator('#invoice-save').click();
  await expect(page).toHaveURL(/\/invoices$/);
  await expect(page.getByText(/Faktura \S+ je uložená\./)).toBeVisible();
}

function row(page: Page, text: string) {
  return page.locator('tbody tr').filter({ hasText: text });
}

test.describe.serial('the invoice list', () => {
  test.use({ storageState: INVOICING_OWNER_STATE });

  test('starts empty and offers the first invoice', async ({ page }) => {
    await page.goto('/invoices');
    await expect(page.getByRole('heading', { name: 'Zatím žádné faktury' })).toBeVisible();
    await expectNoSeriousViolations(page);
    await page.getByRole('main').getByRole('link', { name: 'Nová faktura' }).click();
    await expect(page.locator('form#invoice-form')).toBeVisible();
  });

  test('is reached from the sidebar and the palette', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('navigation').getByRole('link', { name: 'Faktury' }).click();
    await expect(page).toHaveURL(/\/invoices$/);
  });

  test('shows saved invoices, finds them and tells the overdue', async ({ page }) => {
    await issue(page, 'Účetní audit');
    await issue(page, 'Starý dluh', '2026-01-19');

    await page.goto('/invoices');
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await expectNoSeriousViolations(page);

    await page.getByRole('search').getByRole('searchbox').fill('ÚČETNÍ');
    await page.getByRole('search').getByRole('button').click();
    await expect(page.locator('tbody tr')).toHaveCount(1);

    await page.goto('/invoices');
    await page.getByRole('link', { name: /Po splatnosti/ }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody tr')).toContainText('19. 1. 2026');
    await expectNoSeriousViolations(page);
  });

  test('opens the row menu with the keyboard', async ({ page }) => {
    await page.goto('/invoices');
    const first = page.locator('tbody tr').first();
    await first.getByRole('button', { name: /Další akce/ }).focus();
    await page.keyboard.press('ArrowDown');
    const menu = first.getByRole('menu');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Kopírovat' })).toBeFocused();
    await expect(menu).toBeInViewport({ ratio: 1 });
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('menuitem', { name: 'Označit jako doručené' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(first.getByRole('button', { name: /Další akce/ })).toBeFocused();
  });

  test('deletes with a reason, shows the trash and restores', async ({ page }) => {
    await page.goto('/invoices');
    const target = row(page, 'Účetní').or(page.locator('tbody tr').first()).first();
    await target.getByRole('button', { name: /Další akce/ }).click();
    await target.getByRole('menuitem', { name: 'Smazat' }).click();
    await expect(page.getByRole('textbox', { name: /Důvod/ })).toBeVisible();
    await expectNoSeriousViolations(page);

    await page.getByRole('button', { name: 'Přesunout do koše' }).click();
    await expect(page.getByText('Napište, proč fakturu mažete.')).toBeVisible();
    await page.getByRole('textbox', { name: /Důvod/ }).fill('Špatný zákazník');
    await page.getByRole('button', { name: 'Přesunout do koše' }).click();
    await expect(page.getByText(/je v koši\./)).toBeVisible();

    await page.getByRole('link', { name: /Koš/ }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody tr')).toContainText('Špatný zákazník');
    await expect(page.locator('tbody tr')).toContainText('Invoicing Example');
    await expectNoSeriousViolations(page);

    await page.locator('tbody tr').getByRole('button', { name: 'Obnovit' }).click();
    await expect(page.getByText(/je zpět v seznamu\./)).toBeVisible();
    await expect(page).toHaveURL(/tab=trash/);
    await expect(page.getByRole('heading', { name: 'Koš je prázdný' })).toBeVisible();
  });

  test('marks a document delivered, which leaves no delete', async ({ page }) => {
    await page.goto('/invoices');
    const first = page.locator('tbody tr').first();
    await first.getByRole('button', { name: /Další akce/ }).click();
    page.once('dialog', (dialog) => { void dialog.accept(); });
    await first.getByRole('menuitem', { name: 'Označit jako doručené' }).click();
    await expect(page.getByText(/je označená jako doručená\./)).toBeVisible();

    const delivered = page.locator('tbody tr').first();
    await expect(delivered).toContainText('Doručeno');
    await delivered.getByRole('button', { name: /Další akce/ }).click();
    await expect(delivered.getByRole('menuitem', { name: 'Smazat' })).toHaveCount(0);
  });

  test('shows the history of a document with what changed', async ({ page }) => {
    await page.goto('/invoices');
    const first = page.locator('tbody tr').first();
    await first.getByRole('link', { name: 'Upravit' }).click();
    await expect(page).toHaveURL(/\/edit$/);
    await page.locator('#description').fill('Jiný popis');
    await page.locator('#lines-0-price').fill('700');
    await page.waitForTimeout(1100);
    await page.locator('#invoice-save').click();
    await expect(page).toHaveURL(/\/invoices$/);

    await page.locator('tbody tr').first().getByRole('button', { name: /Další akce/ }).click();
    await page.locator('tbody tr').first().getByRole('menuitem', { name: 'Historie' }).click();
    const history = page.locator('[data-invoice-history] li');
    await expect(history.first()).toContainText('faktura vytvořena');
    await expect(page.locator('[data-invoice-history]')).toContainText('Popis');
    await expect(page.locator('[data-invoice-history]')).toContainText('Položka 1 (Práce): Cena');
    await expectNoSeriousViolations(page);
  });

  test('fits a phone without sideways scroll', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.goto('/invoices');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expectNoSidewaysScroll(page);
    await expect(page.locator('tbody tr').first().locator('[data-row-secondary]')).toBeVisible();
    await expectNoSeriousViolations(page);

    const first = page.locator('tbody tr').first();
    await first.getByRole('button', { name: /Další akce/ }).click();
    await expect(first.getByRole('menuitem', { name: 'Upravit' })).toBeVisible();
    await expect(first.getByRole('menu')).toBeInViewport({ ratio: 1 });

    await page.goto('/invoices?tab=trash');
    await expectNoSeriousViolations(page);
  });
});
