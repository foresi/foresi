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

// Each Tab must land on the next id: a date field takes several presses, nothing else does.
async function tabThrough(page: Page, ids: string[]): Promise<void> {
  let current = await page.evaluate(() => document.activeElement?.id ?? '');
  for (const id of ids) {
    for (let press = 0; press < 5; press += 1) {
      await page.keyboard.press('Tab');
      const now = await page.evaluate(() => document.activeElement?.id ?? '');
      if (now !== current || now === id) {
        current = now;
        break;
      }
    }
    expect(current).toBe(id);
  }
}

// What a saved document holds, read back from its edit form.
async function expectSaved(page: Page, number: string, symbol: string): Promise<void> {
  await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]+\/edit$/);
  await expect(page.locator('#number')).toHaveValue(number);
  await expect(page.locator('#variableSymbol')).toHaveValue(symbol);
  await expect(page.getByRole('heading', { level: 1 })).toContainText(number);
}

async function fillLine(page: Page, text = 'Práce', price = '500'): Promise<void> {
  await page.locator('#lines-0-text').fill(text);
  await page.locator('#lines-0-price').fill(price);
}

async function save(page: Page): Promise<void> {
  await page.locator('#invoice-save').click();
}

// A field the list names must stand fully above the list.
async function expectClearOfErrors(page: Page, id: string): Promise<void> {
  await expect.poll(() => page.evaluate((field) => {
    const box = document.getElementById(field)?.getBoundingClientRect();
    const list = document.querySelector('[data-error-summary]')?.getBoundingClientRect();
    return box !== undefined && list !== undefined && box.top >= 0 && box.bottom <= list.top;
  }, id)).toBe(true);
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

  test('is issued with the keyboard alone, in the order of the form', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#firmId').focus();
    await tabThrough(page, ['series', 'customer']);
    await page.keyboard.type('Example Dom');
    await expect(page.getByRole('option', { name: /Example Domestic/ })).toBeVisible();
    await expect(page.locator('#customer-list').getByRole('option')).toHaveCount(1);
    await expect(page.locator('[data-combobox-target=status]')).toHaveText('Nalezeno: 1');
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('#customer')).toHaveAttribute('aria-activedescendant', 'customer-option-0');
    await page.keyboard.press('Enter');
    await expect(page.locator('#customer')).toHaveValue('Example Domestic a.s.');
    await expect(page.locator('[data-customer-summary]')).toContainText('12345679');

    await tabThrough(page, ['customer-add', 'customerEdit', 'customer-contact', 'orderNumber']);
    await page.keyboard.type('PO-1');
    await tabThrough(page, ['description', 'invoice-details', 'issueDate', 'taxPointDate', 'dueDate', 'paymentMethod', 'lines-0-text']);
    await page.keyboard.type('Konzultace');
    await tabThrough(page, ['lines-0-price']);
    await page.keyboard.type('1000,10');
    await tabThrough(page, ['lines-0-quantity']);
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('2');
    await tabThrough(page, ['lines-0-unit']);
    await page.keyboard.type('h');
    await tabThrough(page, ['lines-0-priceType', 'lines-0-vat', 'lines-0-discount']);
    await expect(page.locator('[data-total=payable]')).toHaveText(/2\s420,00\sCZK/);
    await tabThrough(page, ['lines-0-up', 'lines-0-down', 'lines-0-remove', 'invoice-new-line', 'invoice-cancel', 'invoice-more', 'invoice-save']);
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]+\/edit$/);
    await expect(page.getByText(/Faktura \d+ je uložená\./)).toBeVisible();
  });

  test('saves the number of a changed series, not the one before', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#series').selectOption({ label: 'Second' });
    await expect(page.getByRole('heading', { level: 1 })).toContainText('B26001');
    await expect(page.locator('#number')).toHaveValue('B26001');
    await expect(page.locator('#variableSymbol')).toHaveValue('26001');
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);
    await save(page);
    await expectSaved(page, 'B26001', '26001');
    await expect(page.locator('#series option:checked')).toHaveText('Second');
  });

  test('saves the number of the year of the issue date', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#issueDate').fill('2027-02-10');
    await page.locator('#issueDate').dispatchEvent('change');
    await expect(page.locator('#number')).toHaveValue('20270001');
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);
    await save(page);
    await expectSaved(page, '20270001', '20270001');
  });

  test('saves a number typed with the pencil, and the symbol follows it', async ({ page }) => {
    await openPayerForm(page);
    await page.getByLabel('Změnit číslo').click();
    await page.locator('#number').fill('X2026-777');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('X2026-777');
    await expect(page.locator('#variableSymbol')).toHaveValue('2026777');
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);
    await save(page);
    await expectSaved(page, 'X2026-777', '2026777');
  });

  test('keeps a typed number through a series change', async ({ page }) => {
    await openPayerForm(page);
    await page.getByLabel('Změnit číslo').click();
    await page.locator('#number').fill('X2026-778');
    await page.locator('#series').selectOption({ label: 'Second' });
    await expect.poll(() => page.locator('input[name=numberPeek]').inputValue()).toMatch(/^B26/);
    await expect(page.locator('#number')).toHaveValue('X2026-778');
  });

  test('tells the real number when the preview moved under it', async ({ page, context }) => {
    await openPayerForm(page);
    const preview = await page.locator('#number').inputValue();
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);

    const other = await context.newPage();
    await openPayerForm(other);
    await pickCustomer(other, 'Example Domestic a.s.');
    await fillLine(other, 'Rychlejší');
    await save(other);
    await expectSaved(other, preview, preview);
    await other.close();

    await save(page);
    const next = String(Number(preview) + 1);
    await expectSaved(page, next, next);
    await expect(page.getByText(`${preview} mezitím získal jiný doklad`)).toBeVisible();
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
    await page.locator('#lines-1-up').click();
    await expect(page.locator('#lines-0-text')).toHaveValue('Druhá');
    await expect(page.locator('#lines-0-up')).toBeFocused();
    await page.locator('#lines-0-remove').click();
    await expect(page.locator('#lines-0-price')).toHaveValue('100,10');
    await expect(page.locator('#lines-0-text')).toBeFocused();
  });

  test('reorders by dragging the grip, and a click on it leaves nothing armed', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#lines-0-text').fill('První');
    await page.locator('#invoice-new-line').click();
    await page.locator('#lines-1-text').fill('Druhá');
    const grip = page.locator('tr[data-invoice-form-target=line] [data-action*=armDrag]');

    await grip.first().click();
    await expect(page.locator('tr[data-invoice-form-target=line][draggable=true]')).toHaveCount(0);

    await grip.nth(1).dragTo(page.locator('#lines-0-text'), { targetPosition: { x: 10, y: 2 } });
    await expect(page.locator('#lines-0-text')).toHaveValue('Druhá');
    await expect(page.locator('#lines-1-text')).toHaveValue('První');
    await expect(page.locator('input[name="lines[0][text]"]')).toHaveValue('Druhá');
    await expect(page.locator('tr[data-invoice-form-target=line][draggable=true]')).toHaveCount(0);
  });

  test('stops at 100 lines, and they all arrive', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Domestic a.s.');
    await page.evaluate(() => { const add = document.querySelector<HTMLButtonElement>('#invoice-new-line')!; for (let row = 1; row < 105; row += 1) { add.click(); } });
    await expect(page.locator('tr[data-invoice-form-target=line]')).toHaveCount(100);
    await expect(page.locator('#invoice-new-line')).toBeDisabled();
    await expect(page.getByText('Faktura má nejvýše 100 řádků.')).toBeVisible();
    await page.evaluate(() => {
      document.querySelectorAll<HTMLTableRowElement>('tr[data-invoice-form-target=line]').forEach((row, index) => {
        row.querySelector<HTMLInputElement>('[data-cell=text]')!.value = `Řádek ${index + 1}`;
        row.querySelector<HTMLInputElement>('[data-cell=price]')!.value = '10';
      });
    });
    await save(page);
    await expect(page).toHaveURL(/\/edit$/);
    await expect(page.locator('tr[data-invoice-form-target=line]')).toHaveCount(100);
    await expect(page.locator('#lines-99-text')).toHaveValue('Řádek 100');
  });

  test('refuses a form cut off by max_input_vars', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);
    // More rows than the buttons allow, so PHP really drops the end of the post.
    await page.evaluate(() => {
      const row = document.querySelector('tr[data-invoice-form-target=line]')!;
      for (let index = 1; index < 130; index += 1) {
        const copy = row.cloneNode(true) as HTMLElement;
        copy.querySelectorAll('[name]').forEach((field) => field.setAttribute('name', (field.getAttribute('name') ?? '').replace('lines[0]', `lines[${index}]`)));
        row.parentElement!.append(copy);
      }
    });
    const next = await page.locator('#number').inputValue();
    await save(page);
    await expect(page.locator('[data-cut-off]')).toBeVisible();
    await page.goto('/invoices/new');
    await expect(page.locator('#number')).toHaveValue(next);
  });

  test('moves the dates and the preview with the issue date', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#issueDate').fill('2028-02-10');
    await page.locator('#issueDate').dispatchEvent('change');
    await expect(page.locator('#taxPointDate')).toHaveValue('2028-02-10');
    await expect(page.locator('#dueDate')).toHaveValue('2028-02-24');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('20280001');
    await expect(page.locator('#variableSymbol')).toHaveValue('20280001');
  });

  test('takes the defaults of the customer, reverse charge with its code included', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Builder a.s.');
    await expect(page.locator('#lines-0-vat')).toHaveValue('reverse_charge');
    await expect(page.locator('#lines-0-supplyCode')).toBeVisible();
    const due = await page.locator('#issueDate').inputValue();
    expect(new Date(await page.locator('#dueDate').inputValue()).getTime() - new Date(due).getTime()).toBe(30 * 24 * 3600 * 1000);

    await page.locator('#invoice-new-line').click();
    await expect(page.locator('#lines-1-vat')).toHaveValue('reverse_charge');
    await expect(page.locator('#lines-1-supplyCode')).toBeVisible();

    await page.locator('#customer').fill('Example Eur');
    await page.getByRole('option', { name: 'Example Euro a.s.' }).click();
    await expect(page.locator('#lines-0-vat')).toHaveValue('base');
    await expect(page.locator('[data-currency-notice]')).toContainText('EUR');
    await page.locator('#invoice-new-line').click();
    await expect(page.locator('#lines-2-vat')).toHaveValue('base');
  });

  test('drops a hidden supply code so it cannot block the save', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Builder a.s.');
    await fillLine(page, 'Stavba', '100');
    await page.locator('#lines-0-supplyCode').selectOption('4');
    await page.locator('#customer').fill('Example Ger');
    await page.getByRole('option', { name: 'Example German GmbH' }).click();
    await expect(page.locator('#lines-0-vat')).toHaveValue('reverse_charge');
    await expect(page.locator('#lines-0-supplyCode')).toBeHidden();
    await expect(page.locator('#lines-0-supplyCode')).toHaveValue('');
    await save(page);
    await expect(page).toHaveURL(/\/edit$/);
  });

  test('takes a typed name as a pick and leaves the save to the user', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#customer').fill('Example Bui');
    await fillLine(page, 'Stavba', '100');
    await page.locator('#customer-list').evaluate((list) => list.setAttribute('hidden', ''));
    await save(page);
    await expect(page).toHaveURL(/\/invoices\/new$/);
    await expect(page.locator('#lines-0-vat')).toHaveValue('reverse_charge');
    await expect(page.locator('#customer')).toHaveValue('Example Builder a.s.');
  });

  test('reopens the list on ArrowDown and drops an answer to older text', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#customer').fill('Example Dom');
    await expect(page.locator('#customer-list').getByRole('option')).toHaveCount(1);
    await page.keyboard.press('Escape');
    await expect(page.locator('#customer-list')).toBeHidden();
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('#customer-list')).toBeVisible();
    await expect(page.locator('#customer')).toHaveAttribute('aria-activedescendant', 'customer-option-0');

    await page.locator('#customer').fill('Ex');
    await expect(page.locator('#customer-list')).toBeHidden();
    await page.waitForTimeout(400);
    await expect(page.locator('#customer-list')).toBeHidden();
  });

  test('keeps a hand-set due date through a pick', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#dueDate').fill('2026-12-24');
    await pickCustomer(page, 'Example Builder a.s.');
    await expect(page.locator('#dueDate')).toHaveValue('2026-12-24');
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

  for (const [name, size] of [['desktop', { width: 1440, height: 960 }], ['phone', PHONE]] as const) {
    test(`links from the error list to fields that end up clear of it on the ${name}`, async ({ page }) => {
      await page.setViewportSize(size);
      await openPayerForm(page);
      await page.locator('#lines-0-price').fill('abc');
      await page.locator('#dueDate').fill('');
      await save(page);
      await expect(page.locator('[data-error-summary]')).toBeVisible();
      await expectClearOfErrors(page, 'customer');

      for (const id of ['lines-0-price', 'dueDate', 'lines-0-text', 'customer']) {
        await page.locator(`[data-error-summary] a[href="#${id}"]`).click();
        await expect(page.locator(`#${id}`)).toBeFocused();
        await expectClearOfErrors(page, id);
      }

      await page.getByRole('button', { name: 'Zavřít seznam' }).click();
      await expect(page.locator('[data-error-summary]')).toHaveCount(0);
      await expect(page.locator('[aria-invalid=true]').first()).toBeFocused();
    });
  }

  test('keeps a hand-set due date through a refused save', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#dueDate').fill('2026-12-24');
    await save(page);
    await expect(page.locator('[data-error-summary]')).toBeVisible();
    await page.locator('#issueDate').fill('2026-11-05');
    await page.locator('#issueDate').dispatchEvent('change');
    await expect(page.locator('#taxPointDate')).toHaveValue('2026-11-05');
    await expect(page.locator('#dueDate')).toHaveValue('2026-12-24');
  });

  test('says when the totals are out of date', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#lines-0-price').fill('100');
    await expect(page.locator('[data-total=payable]')).toHaveText(/121,00\sCZK/);
    await page.locator('#lines-0-price').fill('abc');
    await expect(page.getByText('Součty nejsou aktuální.')).toBeVisible();
    await page.locator('#lines-0-price').fill('200');
    await expect(page.getByText('Součty nejsou aktuální.')).toBeHidden();
    await expect(page.locator('[data-total=payable]')).toHaveText(/242,00\sCZK/);
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

  test('asks on a real Back, and stays with what was typed', async ({ page }) => {
    await page.goto('/contacts');
    await page.goto('/invoices/new');
    await page.locator('#description').fill('Rozepsáno');
    await expect.poll(() => page.evaluate(() => history.state?.invoiceFormGuard)).toBe(true);

    const dismissed = new Promise<string>((resolve) => { page.once('dialog', (dialog) => { resolve(dialog.message()); void dialog.dismiss(); }); });
    await page.goBack();
    expect(await dismissed).toContain('Opustit formulář');
    await expect(page).toHaveURL(/\/invoices\/new$/);
    await expect(page.locator('#description')).toHaveValue('Rozepsáno');

    page.once('dialog', (dialog) => { void dialog.accept(); });
    await page.goBack();
    await expect(page).toHaveURL(/\/contacts$/);
  });

  test('leaves an untouched form on Back without asking', async ({ page }) => {
    await page.goto('/contacts');
    await page.goto('/invoices/new');
    await page.goBack();
    await expect(page).toHaveURL(/\/contacts$/);
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
