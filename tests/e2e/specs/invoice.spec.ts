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
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

// Moves only with the Tab key, so whatever it reaches is reachable from the keyboard.
async function tabTo(page: Page, id: string): Promise<void> {
  for (let press = 0; press < 80; press += 1) {
    await page.keyboard.press('Tab');
    if ((await page.evaluate(() => document.activeElement?.id ?? '')) === id) {
      return;
    }
  }
  throw new Error(`Tab never reached #${id}`);
}

async function pickCustomer(page: Page, name: string): Promise<void> {
  await page.locator('#customer').fill(name.slice(0, 12));
  await page.getByRole('option', { name }).click();
  await expect(page.locator('[data-customer-summary]')).toBeVisible();
}

async function openPayerForm(page: Page): Promise<void> {
  await page.goto('/invoices/new');
  await expect(page.locator('form#invoice-form')).toBeVisible();
  const firm = page.locator('select[name=firmId]');
  if (await firm.count() > 0 && (await firm.locator('option:checked').textContent()) !== 'Example Payer s.r.o.') {
    await firm.selectOption({ label: 'Example Payer s.r.o.' });
    await expect(page.locator('select[data-cell=vat]').first()).toBeVisible();
  }
}

test.describe.serial('an invoice', () => {
  test.use({ storageState: INVOICING_OWNER_STATE });

  test('is issued with the keyboard alone', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#firmId').focus();
    await tabTo(page, 'series');
    await tabTo(page, 'customer');
    await page.keyboard.type('Example Dom');
    await expect(page.getByRole('option', { name: /Example Domestic/ })).toBeVisible();
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('#customer')).toHaveAttribute('aria-activedescendant', 'customer-option-0');
    await page.keyboard.press('Enter');
    await expect(page.locator('#customer')).toHaveValue('Example Domestic a.s.');
    await expect(page.locator('[data-customer-summary]')).toContainText('12345679');

    await tabTo(page, 'orderNumber');
    await page.keyboard.type('PO-1');
    await tabTo(page, 'description');
    await tabTo(page, 'issueDate');
    await tabTo(page, 'paymentMethod');
    await tabTo(page, 'lines-0-text');
    await page.keyboard.type('Konzultace');
    await tabTo(page, 'lines-0-price');
    await page.keyboard.type('1000,10');
    await tabTo(page, 'lines-0-quantity');
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('2');
    await tabTo(page, 'lines-0-unit');
    await page.keyboard.type('h');
    await tabTo(page, 'lines-0-priceType');
    await tabTo(page, 'lines-0-vat');
    await tabTo(page, 'lines-0-discount');
    await expect(page.locator('[data-total=payable]')).toHaveText(/2\s420,00\sCZK/);

    await tabTo(page, 'invoice-save');
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]+\/edit$/);
    await expect(page.getByText(/Faktura \d+ je uložená\./)).toBeVisible();
  });

  test('counts on the server and adds a line from the last cell', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#lines-0-price').fill('100,10');
    await expect(page.locator('[data-total=payable]')).toHaveText(/121,00\sCZK/);
    await expect(page.locator('[data-total=rounding]')).toHaveText(/-0,12\sCZK/);
    await expect(page.locator('[data-line-total]').first()).toHaveText(/121,12\sCZK/);

    await page.locator('#lines-0-discount').press('Enter');
    await expect(page.locator('#lines-1-text')).toBeFocused();
    await expect(page.locator('#lines-1-quantity')).toHaveValue('1');
    await expect(page).toHaveURL(/\/invoices\/new$/);

    await page.locator('#lines-1-text').fill('Druhá');
    await page.getByRole('button', { name: 'Posunout řádek nahoru' }).nth(1).click();
    await expect(page.locator('#lines-0-text')).toHaveValue('Druhá');
    await page.getByRole('button', { name: 'Odebrat řádek' }).nth(0).click();
    await expect(page.locator('#lines-0-price')).toHaveValue('100,10');
  });

  test('moves the dates and the preview with the issue date', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#issueDate').fill('2027-02-10');
    await page.locator('#issueDate').dispatchEvent('change');
    await expect(page.locator('#taxPointDate')).toHaveValue('2027-02-10');
    await expect(page.locator('#dueDate')).toHaveValue('2027-02-24');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('20270001');
    await expect(page.locator('#variableSymbol')).toHaveValue('20270001');
  });

  test('takes the defaults of the customer, reverse charge with its code included', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Builder a.s.');
    await expect(page.locator('#lines-0-vat')).toHaveValue('reverse_charge');
    await expect(page.locator('#lines-0-supplyCode')).toBeVisible();
    const due = await page.locator('#issueDate').inputValue();
    expect(new Date(await page.locator('#dueDate').inputValue()).getTime() - new Date(due).getTime()).toBe(30 * 24 * 3600 * 1000);

    await page.locator('#customer').fill('Example Eur');
    await page.getByRole('option', { name: 'Example Euro a.s.' }).click();
    await expect(page.locator('#lines-0-vat')).toHaveValue('base');
    await expect(page.locator('[data-currency-notice]')).toContainText('EUR');
  });

  test('offers only customers of the firm', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#customer').fill('Example Vendor');
    await expect(page.getByText('Pro tuto firmu jsme odběratele nenašli.')).toBeAttached();
    await expect(page.locator('#customer-list [role=option]')).toHaveCount(0);
  });

  test('lists every mistake on submit and marks the fields', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#lines-0-price').fill('abc');
    await page.getByRole('button', { name: 'Uložit', exact: true }).click();

    const summary = page.locator('[data-error-summary]');
    await expect(summary).toContainText('Vyberte odběratele.');
    await expect(summary).toContainText('Zadejte cenu');
    await expect(summary).toBeInViewport();
    await expect(page.locator('#customer')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('[aria-invalid=true]').first()).toBeFocused();
    await expectNoSeriousViolations(page);
  });

  test('double-clicked Save makes one invoice', async ({ page }) => {
    await openPayerForm(page);
    const before = await page.locator('#variableSymbol').inputValue();
    await pickCustomer(page, 'Example Domestic a.s.');
    await page.locator('#lines-0-text').fill('Práce');
    await page.locator('#lines-0-price').fill('500');
    await page.getByRole('button', { name: 'Uložit', exact: true }).dblclick();
    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]+\/edit$/);
    await page.goto('/invoices/new');
    expect(Number(await page.locator('#variableSymbol').inputValue())).toBe(Number(before) + 1);
  });

  test('asks before leaving a changed form', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#description').fill('Rozepsáno');
    let asked = '';
    page.once('dialog', (dialog) => { asked = dialog.type(); void dialog.dismiss(); });
    await page.getByRole('link', { name: 'Zrušit' }).click();
    await expect.poll(() => asked).not.toBe('');
    await expect(page).toHaveURL(/\/invoices\/new$/);
  });

  test('still asks after a refused save', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#invoice-save').click();
    await expect(page.locator('[data-error-summary]')).toBeVisible();
    let asked = '';
    page.once('dialog', (dialog) => { asked = dialog.type(); void dialog.dismiss(); });
    await page.getByRole('link', { name: 'Zrušit' }).click();
    await expect.poll(() => asked).not.toBe('');
  });

  test('is clean for axe on the desktop', async ({ page }) => {
    await openPayerForm(page);
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
  });

  test('fits a phone', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await openPayerForm(page);
    await expectNoSidewaysScroll(page);
    await expectNoSeriousViolations(page);
    await page.getByRole('button', { name: 'Nový řádek' }).click();
    await expect(page.locator('#lines-1-text')).toBeFocused();
    await expectNoSidewaysScroll(page);
    await expect(page.locator('[data-action-bar]')).toBeInViewport();
  });

  test('shows the issuer of two firms and a non-payer form without VAT', async ({ page }) => {
    await page.goto('/invoices/new');
    await expect(page.locator('select[name=firmId]')).toBeVisible();
    await page.locator('#lines-0-text').fill('Zůstane');
    await page.locator('select[name=firmId]').selectOption({ label: 'Example Small Trader' });
    await expect(page.locator('select[data-cell=vat]')).toHaveCount(0);
    await expect(page.locator('#lines-0-text')).toHaveValue('Zůstane');
    await expect(page.locator('[data-total=vat]')).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText('DPH');
    await page.locator('#lines-0-price').fill('1000,50');
    await expect(page.locator('[data-total=payable]')).toHaveText(/1\s001,00\sCZK/);
    await expectNoSeriousViolations(page);
  });

  test('stays what it was when edited, and a second save of the old form asks to reload', async ({ page, context }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Domestic a.s.');
    await page.locator('#lines-0-text').fill('Práce');
    await page.locator('#lines-0-price').fill('500');
    await page.getByRole('button', { name: 'Uložit', exact: true }).click();
    await expect(page).toHaveURL(/\/edit$/);
    await expect(page.locator('select[name=firmId]')).toHaveCount(0);
    await expect(page.locator('[data-firm-text]')).toHaveText('Example Payer s.r.o.');

    const other = await context.newPage();
    await other.goto(page.url());
    await other.locator('#description').fill('Jiný');
    await other.getByRole('button', { name: 'Uložit', exact: true }).click();
    await expect(other.getByText(/je uložená/)).toBeVisible();
    await other.close();

    await page.locator('#description').fill('Starý');
    await page.getByRole('button', { name: 'Uložit', exact: true }).click();
    await expect(page.locator('[data-reload-prompt]')).toContainText('mezitím uložil');
    await expectNoSeriousViolations(page);
  });
});
