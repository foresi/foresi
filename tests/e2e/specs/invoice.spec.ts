// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { INVOICING_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

// On a phone the action bar floats over whatever field the scroll leaves below it, which
// axe reads as a target too small. Scrolling moves it away, so the check runs with it in flow.
async function expectNoSeriousViolationsWithoutTheFloatingBar(page: Page): Promise<void> {
  const style = await page.addStyleTag({ content: '[data-action-bar] { position: static !important; }' });
  await expectNoSeriousViolations(page);
  await style.evaluate((element) => element.remove());
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

// A save lands on the list: the row of the number leads to the document, the newest of that number first.
async function openFromList(page: Page, number: string): Promise<void> {
  await page.goto(`/invoices?q=${encodeURIComponent(number)}`);
  await page.getByRole('link', { name: number, exact: true }).first().click();
  await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]+\/edit$/);
}

// The number the list says was saved, and the document opened from there.
async function openSaved(page: Page): Promise<string> {
  await expect(page).toHaveURL(/\/invoices$/);
  const flash = await page.locator('[role=status]').filter({ hasText: /Faktura/ }).first().textContent();
  const number = /Faktura (?:uložena jako )?([^\s:.]+)/.exec(flash ?? '')?.[1] ?? '';
  await openFromList(page, number);
  return page.url();
}

// What a saved document holds, read back from its edit form, against what the form showed and what the flash says.
async function expectSaved(page: Page, number: string, symbol: string, flash: RegExp | string = `Faktura ${number} je uložená.`): Promise<void> {
  await expect(page).toHaveURL(/\/invoices$/);
  await expect(page.locator('[role=status]').filter({ hasText: flash })).toBeVisible();
  await openFromList(page, number);
  await expect(page.locator('#number')).toHaveValue(number);
  await expect(page.locator('#variableSymbol')).toHaveValue(symbol);
  await expect(page.getByRole('heading', { level: 1 })).toContainText(number);
}

// Shown before the save; each number path compares this with what was stored.
async function expectShown(page: Page, number: string, symbol: string): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toContainText(number);
  await expect(page.locator('#number')).toHaveValue(number);
  await expect(page.locator('#variableSymbol')).toHaveValue(symbol);
}

async function issueOne(page: Page): Promise<string> {
  await openPayerForm(page);
  await pickCustomer(page, 'Example Domestic a.s.');
  await fillLine(page);
  await save(page);
  return openSaved(page);
}

async function nextOfSecond(page: Page): Promise<string> {
  const id = await page.locator('#series option', { hasText: 'Second' }).getAttribute('value');
  const answer = await page.request.get(`/invoices/next-number?series=${id}&date=${await page.locator('#issueDate').inputValue()}`);
  return ((await answer.json()) as { number: string }).number;
}

async function setIssueDate(page: Page, date: string): Promise<void> {
  await page.locator('#issueDate').fill(date);
  await page.locator('#issueDate').dispatchEvent('change');
}

// Types as a person does: Chrome asks before unload only after a real gesture.
async function type(page: Page, id: string, text: string): Promise<void> {
  if (id === '#description') {
    await openMore(page);
  }
  await page.locator(id).click();
  await page.keyboard.type(text);
}

// A real Back against the browser's own prompt; the outcome is read once navigation has settled.
async function backAnswering(page: Page, leave: boolean): Promise<void> {
  const asked = new Promise<string>((resolve) => {
    page.once('dialog', (dialog) => { resolve(dialog.type()); void (leave ? dialog.accept() : dialog.dismiss()); });
  });
  const going = page.goBack({ timeout: 3000 }).catch(() => null);
  expect(await asked).toBe('beforeunload');
  await going;
  await page.waitForLoadState('load');
  await page.waitForTimeout(400);
}

// The description lives in "More details", closed on a new form.
async function openMore(page: Page): Promise<void> {
  if (await page.locator('details[data-more-details]').getAttribute('open') === null) {
    await page.locator('#invoice-details').click();
  }
}

async function describe(page: Page, text: string): Promise<void> {
  await openMore(page);
  await page.locator('#description').fill(text);
}

async function lineAction(page: Page, index: number, action: 'up' | 'down' | 'remove'): Promise<void> {
  await page.locator(`#lines-${index}-menu`).click();
  await page.locator(`#lines-${index}-${action}`).click();
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

    await tabThrough(page, ['customer-add', 'customerEdit', 'customer-contact', 'invoice-details', 'issueDate', 'taxPointDate', 'dueDate', 'paymentMethod', 'lines-0-text']);
    await page.keyboard.type('Konzultace');
    await tabThrough(page, ['lines-0-price']);
    await page.keyboard.type('1000,10');
    await tabThrough(page, ['lines-0-quantity']);
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('2');
    await tabThrough(page, ['lines-0-unit']);
    await page.keyboard.type('h');
    await tabThrough(page, ['lines-0-priceType', 'lines-0-vat', 'lines-0-discount-add']);
    await expect(page.locator('[data-total=payable]')).toHaveText(/2\s420,00\sKč/);
    await tabThrough(page, ['lines-0-menu', 'invoice-new-line', 'invoice-cancel', 'invoice-more', 'invoice-save']);
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/invoices$/);
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
    await setIssueDate(page, '2027-02-10');
    await expectShown(page, '20270001', '20270001');
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

  test('saves the default path as shown', async ({ page }) => {
    await openPayerForm(page);
    const number = await page.locator('#number').inputValue();
    await expectShown(page, number, number);
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);
    await save(page);
    await expectSaved(page, number, number);
  });

  test('saves after a firm change on the date of the form, with a typed number gone', async ({ page }) => {
    await openPayerForm(page);
    await setIssueDate(page, '2029-03-01');
    await expect(page.locator('#number')).toHaveValue('20290001');
    await page.getByLabel('Změnit číslo').click();
    await page.locator('#number').fill('X-TYPED-1');
    await page.locator('select[name=firmId]').selectOption({ label: 'Example Small Trader' });
    await expect(page.getByText('Firma se změnila')).toBeVisible();
    await expectShown(page, '20290001', '20290001');
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);
    await save(page);
    await expectSaved(page, '20290001', '20290001');
  });

  test('saves an edit moved to another series', async ({ page }) => {
    await page.goto(await issueOne(page));
    const moved = await nextOfSecond(page);
    await page.locator('#series').selectOption({ label: 'Second' });
    await expectShown(page, moved, moved.replace(/\D/g, ''));
    await save(page);
    await expectSaved(page, moved, moved.replace(/\D/g, ''));
  });

  test('moves an edit there and back across a re-render and keeps the number', async ({ page }) => {
    await page.goto(await issueOne(page));
    const original = await page.locator('#number').inputValue();
    const series = await page.locator('#series option:checked').textContent();
    await page.locator('#series').selectOption({ label: 'Second' });
    await expect(page.locator('#number')).not.toHaveValue(original);
    await page.locator('#lines-0-price').fill('abc');
    await save(page);
    await expect(page.locator('[data-error-summary]')).toBeVisible();

    await page.locator('#series').selectOption({ label: series ?? '' });
    await expectShown(page, original, original);
    await page.locator('#lines-0-price').fill('500');
    await save(page);
    await expectSaved(page, original, original);
  });

  test('moves an edit to another series and year together', async ({ page }) => {
    await page.goto(await issueOne(page));
    await page.locator('#series').selectOption({ label: 'Second' });
    await expect(page.locator('#number')).toHaveValue(/^B26/);
    await setIssueDate(page, '2031-01-15');
    await page.locator('#taxPointDate').fill('2031-01-15');
    await expectShown(page, 'B31001', '31001');
    await save(page);
    await expectSaved(page, 'B31001', '31001');
  });

  test('keeps the number of an edit when only the year changes', async ({ page }) => {
    await page.goto(await issueOne(page));
    const number = await page.locator('#number').inputValue();
    await setIssueDate(page, '2031-02-15');
    await page.waitForTimeout(300);
    await expectShown(page, number, number);
    await save(page);
    await expectSaved(page, number, number);
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
    await expectSaved(page, next, next, `${preview} mezitím získal jiný doklad`);
  });

  test('counts on the server and adds a line from the last cell', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#lines-0-price').fill('100,10');
    await expect(page.locator('[data-total=payable]')).toHaveText(/121,00\sKč/);
    await expect(page.locator('[data-total=rounding]')).toHaveText(/-0,12\sKč/);
    await expect(page.locator('[data-line-total]').first()).toHaveText(/121,12\sKč/);

    await page.locator('#lines-0-vat').press('Enter');
    await expect(page.locator('#lines-1-text')).toBeFocused();
    await expect(page.locator('#lines-1-quantity')).toHaveValue('1');
    await expect(page).toHaveURL(/\/invoices\/new$/);

    await page.locator('#lines-1-text').fill('Druhá');
    await lineAction(page, 1, 'up');
    await expect(page.locator('#lines-0-text')).toHaveValue('Druhá');
    await expect(page.locator('#lines-0-menu')).toBeFocused();
    await lineAction(page, 0, 'remove');
    await expect(page.locator('#lines-0-price')).toHaveValue('100,10');
    await expect(page.locator('#lines-0-text')).toBeFocused();
  });

  test('reorders by dragging the grip, and a click on it leaves nothing armed', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#lines-0-text').fill('První');
    await page.locator('#invoice-new-line').click();
    await page.locator('#lines-1-text').fill('Druhá');
    const grip = page.locator('tbody[data-invoice-form-target=line] [data-action*=armDrag]');

    await grip.first().click();
    await expect(page.locator('tbody[data-invoice-form-target=line][draggable=true]')).toHaveCount(0);

    await grip.nth(1).dragTo(page.locator('#lines-0-text'), { targetPosition: { x: 10, y: 2 } });
    await expect(page.locator('#lines-0-text')).toHaveValue('Druhá');
    await expect(page.locator('#lines-1-text')).toHaveValue('První');
    await expect(page.locator('input[name="lines[0][text]"]')).toHaveValue('Druhá');
    await expect(page.locator('tbody[data-invoice-form-target=line][draggable=true]')).toHaveCount(0);
  });

  test('stops at 100 lines, and they all arrive', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Domestic a.s.');
    await page.evaluate(() => { const add = document.querySelector<HTMLButtonElement>('#invoice-new-line')!; for (let row = 1; row < 105; row += 1) { add.click(); } });
    await expect(page.locator('tbody[data-invoice-form-target=line]')).toHaveCount(100);
    await expect(page.locator('#invoice-new-line')).toBeDisabled();
    await expect(page.getByText('Faktura má nejvýše 100 řádků.')).toBeVisible();
    await page.evaluate(() => {
      document.querySelectorAll<HTMLTableRowElement>('tbody[data-invoice-form-target=line]').forEach((row, index) => {
        row.querySelector<HTMLInputElement>('[data-cell=text]')!.value = `Řádek ${index + 1}`;
        row.querySelector<HTMLInputElement>('[data-cell=price]')!.value = '10';
      });
    });
    await save(page);
    await openSaved(page);
    await expect(page.locator('tbody[data-invoice-form-target=line]')).toHaveCount(100);
    await expect(page.locator('#lines-99-text')).toHaveValue('Řádek 100');
  });

  test('refuses a form cut off by max_input_vars', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);
    // More rows than the buttons allow, so PHP really drops the end of the post.
    await page.evaluate(() => {
      const row = document.querySelector('tbody[data-invoice-form-target=line]')!;
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
    await expect(page).toHaveURL(/\/invoices$/);
  });

  test('drops the code when a foreign VAT ID is typed on the document, and saves', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Builder a.s.');
    await fillLine(page, 'Stavba', '100');
    await page.locator('#lines-0-supplyCode').selectOption('4');
    await page.locator('#customerEdit').check();
    await page.locator('#customer-vatId').fill('DE123456789');
    await expect(page.locator('#lines-0-supplyCode')).toBeHidden();
    await save(page);
    await expect(page).toHaveURL(/\/invoices$/);
  });

  test('forgets the treatment of a cleared customer for new lines', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Builder a.s.');
    await page.locator('#customer').fill('');
    await page.locator('#invoice-new-line').click();
    await expect(page.locator('#lines-1-vat')).toHaveValue('base');
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

  test('offers a contact without the customer role, who becomes a customer on the save', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#customer').fill('Example Vendor');
    await page.getByRole('option', { name: 'Example Vendor' }).click();
    await expect(page.locator('[data-customer-summary]')).toBeVisible();
    await fillLine(page);
    await save(page);
    await expect(page).toHaveURL(/\/invoices$/);

    await page.goto('/contacts?q=Example%20Vendor');
    await page.getByRole('link', { name: 'Example Vendor' }).first().click();
    await page.goto(`${page.url()}/edit`);
    await expect(page.locator('input[type=checkbox][value=customer]:checked')).not.toHaveCount(0);
  });

  test('says what to do when nothing is found', async ({ page }) => {
    await openPayerForm(page);
    await page.locator('#customer').fill('Nobody at all');
    await expect(page.getByText('Nic nenalezeno. Použijte plus nebo adresář.', { exact: true }).first()).toBeVisible();
  });

  test('the plus adds a customer from the register in a new tab and picks it with its defaults', async ({ page, context }) => {
    await openPayerForm(page);
    await fillLine(page, 'Zůstane', '750');
    const [tab] = await Promise.all([context.waitForEvent('page'), page.locator('#customer-add').click()]);
    await tab.locator('#lookup').fill('12345687');
    await tab.getByRole('button', { name: /Example Company s\.r\.o\./ }).click();
    await expect(tab.locator('#displayName')).toHaveValue('Example Company s.r.o.');
    if (await tab.locator('#street').inputValue() === '') {
      await tab.locator('#street').fill('Example Street 1');
      await tab.locator('#city').fill('Example City');
      await tab.locator('#postalCode').fill('100 00');
    }
    await tab.locator('button[type=submit]').last().click();
    await expect(tab).toHaveURL(/\/contacts\/[0-9a-f-]+$/);

    await expect(page.locator('#customer')).toHaveValue('Example Company s.r.o.');
    await expect(page.locator('input[name=customerId]')).not.toHaveValue('');
    await expect(page.locator('[data-customer-summary]')).toContainText('12345687');
    await expect(page.locator('#lines-0-text')).toHaveValue('Zůstane');
    await expect(page.locator('#lines-0-price')).toHaveValue('750,00');
    await page.screenshot({ path: `${SHOTS}/plus-picked-desktop.png`, fullPage: true });
    await tab.close();
    await save(page);
    await expect(page).toHaveURL(/\/invoices$/);
  });

  test('the plus works on a phone', async ({ page, context }) => {
    await page.setViewportSize(PHONE);
    await openPayerForm(page);
    const [tab] = await Promise.all([context.waitForEvent('page'), page.locator('#customer-add').click()]);
    await tab.setViewportSize(PHONE);
    await tab.locator('#kind').selectOption('person');
    await tab.locator('#displayName').fill('Example Phone Person');
    await tab.locator('#street').fill('Example Street 9');
    await tab.locator('#city').fill('Example City');
    await tab.locator('#postalCode').fill('100 00');
    await tab.locator('button[type=submit]').last().click();
    await expect(tab).toHaveURL(/\/contacts\/[0-9a-f-]+$/);
    await expect(page.locator('#customer')).toHaveValue('Example Phone Person');
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/plus-picked-phone.png`, fullPage: true });
    await tab.close();
  });

  test('a contact saved in another tab is not picked unless the plus asked for it', async ({ page, context }) => {
    await openPayerForm(page);
    const other = await context.newPage();
    await other.goto('/contacts/new');
    await other.locator('#kind').selectOption('person');
    await other.locator('#displayName').fill('Example Unrelated Person');
    await other.locator('#street').fill('Example Street 8');
    await other.locator('#city').fill('Example City');
    await other.locator('#postalCode').fill('100 00');
    await other.locator('button[type=submit]').last().click();
    await expect(other).toHaveURL(/\/contacts\/[0-9a-f-]+$/);
    await other.close();
    await expect(page.locator('#customer')).toHaveValue('');
  });

  test('a discount is a sub-row: the line keeps its full amount and the deduction stands below', async ({ page }) => {
    await openPayerForm(page);
    await fillLine(page, 'Práce', '1000');
    await expect(page.locator('#lines-0-discount')).toBeHidden();
    await page.locator('#lines-0-discount-add').click();
    await expect(page.locator('#lines-0-discount')).toBeFocused();
    await page.keyboard.type('10');
    await expect(page.locator('[data-line-total]').first()).toHaveText(/1\s210,00\sKč/);
    await expect(page.locator('[data-line-discount]').first()).toHaveText(/-121,00\sKč/);
    await expect(page.locator('[data-total=payable]')).toHaveText(/1\s089,00\sKč/);
    await page.locator('#lines-0-discount-remove').click();
    await expect(page.locator('#lines-0-discount')).toBeHidden();
    await expect(page.locator('#lines-0-discount-add')).toBeFocused();
    await expect(page.locator('[data-total=payable]')).toHaveText(/1\s210,00\sKč/);
  });

  test('a dragged line takes its discount along', async ({ page }) => {
    await openPayerForm(page);
    await fillLine(page, 'První', '100');
    await page.locator('#lines-0-discount-add').click();
    await page.keyboard.type('5');
    await page.getByRole('button', { name: 'Nový řádek' }).click();
    await page.locator('#lines-1-text').fill('Druhá');
    await page.locator('tbody[data-invoice-form-target=line] [data-action*=armDrag]').nth(1).dragTo(page.locator('#lines-0-text'), { targetPosition: { x: 10, y: 2 } });
    await expect(page.locator('#lines-1-text')).toHaveValue('První');
    await expect(page.locator('#lines-1-discount')).toHaveValue('5');
    await expect(page.locator('#lines-0-discount')).toBeHidden();
  });

  test('keeps numbers on one edge and rows at one height, with and without a discount', async ({ page }) => {
    const box = async (selector: string) => (await page.locator(selector).boundingBox()) ?? { x: 0, y: 0, width: 0, height: 0 };
    const edges = async (selector: string) => Promise.all((await page.locator(selector).all()).map(async (element) => {
      const measured = (await element.boundingBox()) ?? { x: 0, width: 0 };
      return Math.round(measured.x + measured.width);
    }));
    await openPayerForm(page);
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page, 'Jedna položka', '1000');
    await expect(page.locator('[data-line-total]').first()).toHaveText(/1\s210,00/);
    await page.screenshot({ path: `${SHOTS}/discount-one-line.png`, fullPage: true });
    const plain = (await box('#lines-0-text')).height;
    const rowPlain = await page.locator('tbody[data-invoice-form-target=line] > tr').first().evaluate((row) => row.getBoundingClientRect().height);
    const totalY = (await box('[data-line-total]')).y;

    await page.locator('#lines-0-discount-add').click();
    await page.keyboard.type('10');
    await expect(page.locator('[data-line-discount]').first()).not.toHaveText('');
    await page.screenshot({ path: `${SHOTS}/discount-with-discount.png`, fullPage: true });
    expect((await box('#lines-0-text')).height).toBe(plain);
    expect((await box('#lines-0-discount')).height).toBe(plain);
    expect(await page.locator('tbody[data-invoice-form-target=line] > tr').first().evaluate((row) => row.getBoundingClientRect().height)).toBe(rowPlain);
    expect((await box('[data-line-total]')).y).toBe(totalY);
    const discountRow = await page.locator('#lines-0-discount-box').evaluate((row) => row.getBoundingClientRect().height);
    expect(discountRow).toBe(rowPlain);

    await page.getByRole('button', { name: 'Nový řádek' }).click();
    await page.locator('#lines-1-text').fill('Velmi dlouhý text položky, který se do pole nevejde celý a musí v něm zůstat na jednom řádku bez přetečení');
    await page.locator('#lines-1-price').fill('123456,78');
    await page.getByRole('button', { name: 'Nový řádek' }).click();
    await page.locator('#lines-2-text').fill('Třetí');
    await page.locator('#lines-2-price').fill('50');
    await page.locator('#lines-2-discount-add').click();
    await page.keyboard.type('12,5');
    await expect(page.locator('[data-line-discount]').nth(2)).not.toHaveText('');
    await page.locator('#lines-1-text').click();
    await page.screenshot({ path: `${SHOTS}/discount-mixed-lines.png`, fullPage: true });
    const rights = [...await edges('[data-line-total]'), ...await edges('tbody tr:not([hidden]) [data-line-discount]')];
    expect(new Set(rights).size).toBe(1);
    const prices = await page.locator('#lines-0-price').evaluate((field) => getComputedStyle(field).paddingRight);
    expect((await box('#lines-0-discount')).x + (await box('#lines-0-discount')).width).toBe((await box('#lines-0-price')).x + (await box('#lines-0-price')).width);
    await expectNoSidewaysScroll(page);

    await page.locator('#lines-0-vat').selectOption('reverse_charge');
    await page.locator('#lines-0-supplyCode').selectOption({ index: 1 });
    await page.locator('#lines-1-text').click();
    await expect(page.locator('[data-line-total]').first()).toHaveText(/1\s000,00\sKč/);
    await expect(page.locator('[data-line-discount]').first()).toHaveText(/-100,00\sKč/);
    await page.screenshot({ path: `${SHOTS}/discount-reverse-charge.png`, fullPage: true });
    expect(new Set([...await edges('[data-line-total]'), ...await edges('tbody tr:not([hidden]) [data-line-discount]')]).size).toBe(1);
  });

  test('a discount stacks under its card on a phone', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await openPayerForm(page);
    await fillLine(page, 'Práce', '1000');
    await page.locator('#lines-0-discount-add').click();
    await page.keyboard.type('10');
    await expect(page.locator('[data-line-discount]').first()).not.toHaveText('');
    await expectNoSidewaysScroll(page);
    await page.locator('#lines-0-discount').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${SHOTS}/discount-phone.png` });
    const card = await page.locator('tbody[data-invoice-form-target=line]').first().boundingBox();
    const sub = await page.locator('#lines-0-discount-box').boundingBox();
    const total = await page.locator('[data-line-total]').first().boundingBox();
    const deduction = await page.locator('[data-line-discount]').first().boundingBox();
    expect(Math.round((deduction?.x ?? 0) + (deduction?.width ?? 0))).toBe(Math.round((total?.x ?? 0) + (total?.width ?? 0)));
    expect(sub?.y ?? 0).toBeGreaterThan(card?.y ?? 0);
    expect((sub?.y ?? 0) + (sub?.height ?? 0)).toBeLessThanOrEqual((card?.y ?? 0) + (card?.height ?? 0));
  });

  test('moves a line from its menu with the keyboard', async ({ page }) => {
    await openPayerForm(page);
    await fillLine(page, 'První');
    await page.getByRole('button', { name: 'Nový řádek' }).click();
    await page.locator('#lines-1-text').fill('Druhá');
    await page.locator('#lines-1-menu').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#lines-1-up')).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#lines-0-text')).toHaveValue('Druhá');
    await expect(page.locator('#lines-0-menu')).toBeFocused();
    await page.locator('#lines-0-menu').click();
    await page.keyboard.press('Escape');
    await expect(page.locator('#lines-0-menu')).toBeFocused();
  });

  test('groups a price as it is typed and keeps the caret', async ({ page }) => {
    await openPayerForm(page);
    const price = page.locator('#lines-0-price');
    await price.click();
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('1234567');
    await expect(price).toHaveValue('1\u00a0234\u00a0567');
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('Backspace');
    await expect(price).toHaveValue('123\u00a0567');
    await page.keyboard.type('9');
    await expect(price).toHaveValue('1\u00a0239\u00a0567');
    await page.keyboard.press('Tab');
    await expect(price).toHaveValue('1\u00a0239\u00a0567,00');
  });

  test('deletes in the middle of a formatted price', async ({ page }) => {
    await openPayerForm(page);
    const price = page.locator('#lines-0-price');
    await price.click();
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('1234,50');
    await page.keyboard.press('Home');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Delete');
    await expect(price).toHaveValue('124,50');
    await page.keyboard.type('9');
    await expect(price).toHaveValue('1\u00a0294,50');
  });

  test('trims a quantity and reads what is pasted', async ({ page }) => {
    await openPayerForm(page);
    const quantity = page.locator('#lines-0-quantity');
    await quantity.fill('1,50');
    await quantity.blur();
    await expect(quantity).toHaveValue('1,5');

    const price = page.locator('#lines-0-price');
    for (const [pasted, shown] of [['1 000,50', '1\u00a0000,50'], ['1000.5', '1\u00a0000,50'], ['1,000.50', '1\u00a0000,50']]) {
      await price.click();
      await page.keyboard.press('ControlOrMeta+a');
      await page.evaluate((text) => document.execCommand('insertText', false, text), pasted);
      await price.dispatchEvent('input', { inputType: 'insertFromPaste' });
      await price.blur();
      await expect(price).toHaveValue(shown);
    }
    await expect(page.locator('[data-line-total]').first()).toHaveText(/1\s815,91\sKč/);
  });

  test('leads from a firm problem to the firm settings in a new tab and keeps the form', async ({ page, context }) => {
    await page.goto('/invoices/new');
    await page.locator('select[name=firmId]').selectOption({ label: 'Example No Account s.r.o.' });
    await expect(page.locator('[data-bank-note]')).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/no-account-note.png`, fullPage: true });
    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page, 'Zůstane');
    await save(page);
    const link = page.locator('[data-error-summary] a[target=_blank]').first();
    await expect(link).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/error-links.png`, fullPage: true });
    const [tab] = await Promise.all([context.waitForEvent('page'), link.click()]);
    await expect(tab).toHaveURL(/\/settings\/firms\/[0-9a-f-]+\/edit$/);
    await tab.close();
    await expect(page.locator('#lines-0-text')).toHaveValue('Zůstane');
    for (const anchor of await page.locator('[data-error-summary] a[data-error-link]:not([target])').all()) {
      await anchor.click();
      await expect.poll(() => page.evaluate(() => document.activeElement?.tagName)).not.toBe('BODY');
    }
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
    await expect(page.locator('[data-total=payable]')).toHaveText(/121,00\sKč/);
    await page.locator('#lines-0-price').fill('abc');
    await expect(page.getByText('Součty nejsou aktuální.')).toBeVisible();
    await page.locator('#lines-0-price').fill('200');
    await expect(page.getByText('Součty nejsou aktuální.')).toBeHidden();
    await expect(page.locator('[data-total=payable]')).toHaveText(/242,00\sKč/);
  });

  test('double-clicked Save makes one invoice', async ({ page }) => {
    await openPayerForm(page);
    const before = await page.locator('#variableSymbol').inputValue();
    await pickCustomer(page, 'Example Domestic a.s.');
    await page.locator('#lines-0-text').fill('Práce');
    await page.locator('#lines-0-price').fill('500');
    await page.getByRole('button', { name: 'Uložit', exact: true }).dblclick();
    await expect(page).toHaveURL(/\/invoices$/);
    await expect(page.getByText('Tento formulář už byl uložen.')).toHaveCount(0);
    await page.goto('/invoices/new');
    expect(Number(await page.locator('#variableSymbol').inputValue())).toBe(Number(before) + 1);
  });

  test('asks before a link leaves a changed form, and after a refused save', async ({ page }) => {
    await openPayerForm(page);
    await type(page, '#description', 'Rozepsáno');
    let asked = '';
    page.once('dialog', (dialog) => { asked = dialog.type(); void dialog.dismiss(); });
    await page.locator('#invoice-cancel').click();
    await expect.poll(() => asked).toBe('beforeunload');
    await page.waitForTimeout(300);
    await expect(page).toHaveURL(/\/invoices\/new$/);
    await expect(page.locator('#description')).toHaveValue('Rozepsáno');

    await save(page);
    await expect(page.locator('[data-error-summary]')).toBeVisible();
    asked = '';
    page.once('dialog', (dialog) => { asked = dialog.type(); void dialog.dismiss(); });
    await page.locator('#invoice-cancel').click();
    await expect.poll(() => asked).toBe('beforeunload');
  });

  test('asks on a real Back from a new form: Stay keeps it, Leave and Forward work', async ({ page }) => {
    await page.goto('/contacts');
    await page.goto('/invoices/new');
    await type(page, '#description', 'Rozepsáno');

    await backAnswering(page, false);
    await expect(page).toHaveURL(/\/invoices\/new$/);
    await expect(page.locator('#description')).toHaveValue('Rozepsáno');

    await backAnswering(page, true);
    await expect(page).toHaveURL(/\/contacts$/);

    await page.goForward();
    await expect(page).toHaveURL(/\/invoices\/new$/);
    await expect(page.locator('form#invoice-form')).toBeVisible();
    page.on('dialog', (dialog) => void dialog.accept());
    await page.goBack();
    await expect(page).toHaveURL(/\/contacts$/);
  });

  test('arrives by Turbo as a full page and still asks on Back', async ({ page }) => {
    await page.goto('/contacts');
    await page.evaluate(() => { (window as unknown as { Turbo: { visit(url: string): void } }).Turbo.visit('/invoices/new'); });
    await expect(page.locator('form#invoice-form')).toBeVisible();
    await type(page, '#description', 'Rozepsáno');

    await backAnswering(page, false);
    await expect(page.locator('#description')).toHaveValue('Rozepsáno');
    await backAnswering(page, true);
    await expect(page).toHaveURL(/\/contacts$/);
  });

  test('asks on a real Back from an edit', async ({ page }) => {
    const url = await issueOne(page);
    await page.goto('/contacts');
    await page.goto(url);
    await type(page, '#description', 'Změněno');

    await backAnswering(page, false);
    await expect(page).toHaveURL(url);
    await expect(page.locator('#description')).toHaveValue('PráceZměněno');

    await backAnswering(page, true);
    await expect(page).toHaveURL(/\/contacts$/);
  });

  test('asks on a real Back after a refused save', async ({ page }) => {
    await page.goto('/contacts');
    await page.goto('/invoices/new');
    await type(page, '#description', 'Odmítnuto');
    await save(page);
    await expect(page.locator('[data-error-summary]')).toBeVisible();

    await backAnswering(page, false);
    await expect(page.locator('[data-error-summary]')).toBeVisible();
    await expect(page.locator('#description')).toHaveValue('Odmítnuto');

    await backAnswering(page, true);
    await expect(page).toHaveURL(/\/invoices\/new$/);
    await expect(page.locator('[data-error-summary]')).toHaveCount(0);
  });

  test('a save lifts the guard, and reload asks', async ({ page }) => {
    await openPayerForm(page);
    await type(page, '#description', 'Uložím');
    let asked = 0;
    const count = (): void => { asked += 1; };
    page.on('dialog', (dialog) => { count(); void dialog.accept(); });
    await page.reload();
    await expect.poll(() => asked).toBe(1);

    await pickCustomer(page, 'Example Domestic a.s.');
    await fillLine(page);
    await save(page);
    await expect(page).toHaveURL(/\/invoices$/);
    expect(asked).toBe(1);
  });

  test('a firm change is a submit and asks nothing', async ({ page }) => {
    await page.goto('/invoices/new');
    await type(page, '#description', 'Zůstane');
    let asked = false;
    page.on('dialog', (dialog) => { asked = true; void dialog.dismiss(); });
    const firm = page.locator('select[name=firmId]');
    const other = (await firm.locator('option:checked').textContent()) === 'Example Payer s.r.o.' ? 'Example Small Trader' : 'Example Payer s.r.o.';
    await firm.selectOption({ label: other });
    await expect(page.getByText('Firma se změnila')).toBeVisible();
    await expect(page.locator('#description')).toHaveValue('Zůstane');
    expect(asked).toBe(false);
  });

  test('leaves an untouched form on Back without asking', async ({ page }) => {
    await page.goto('/contacts');
    await page.goto('/invoices/new');
    await page.goBack();
    await expect(page).toHaveURL(/\/contacts$/);
  });

  test('is clean for axe on the desktop', async ({ page }) => {
    await openPayerForm(page);
    await page.screenshot({ path: `${SHOTS}/form-desktop.png`, fullPage: true });
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
  });

  test('fits a phone', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await openPayerForm(page);
    await expectNoSidewaysScroll(page);
    await expectNoSeriousViolationsWithoutTheFloatingBar(page);
    await page.locator('#lines-0-discount-add').click();
    await page.locator('#lines-0-menu').click();
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/lines-phone.png`, fullPage: true });
    await page.keyboard.press('Escape');
    await expectNoSeriousViolationsWithoutTheFloatingBar(page);
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
    await expect(page.locator('[data-total=payable]')).toHaveText(/1\s001,00\sKč/);
    await expectNoSeriousViolations(page);
  });

  test('stays what it was when edited, and a second save of the old form asks to reload', async ({ page, context }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Domestic a.s.');
    await page.locator('#lines-0-text').fill('Práce');
    await page.locator('#lines-0-price').fill('500');
    await page.getByRole('button', { name: 'Uložit', exact: true }).click();
    await openSaved(page);
    await expect(page.locator('select[name=firmId]')).toHaveCount(0);
    await expect(page.locator('[data-firm-text]')).toHaveText('Example Payer s.r.o.');

    const other = await context.newPage();
    await other.goto(page.url());
    await describe(other, 'Jiný');
    await other.getByRole('button', { name: 'Uložit', exact: true }).click();
    await expect(other.getByText(/je uložená/)).toBeVisible();
    await other.close();

    await describe(page, 'Starý');
    await page.getByRole('button', { name: 'Uložit', exact: true }).click();
    await expect(page.locator('[data-reload-prompt]')).toContainText('mezitím uložil');
    await expectNoSeriousViolations(page);
  });

  test('a copy opens from the list unsaved, and saving gives a new number and leaves the source', async ({ page }) => {
    await openPayerForm(page);
    await pickCustomer(page, 'Example Domestic a.s.');
    await describe(page, 'Zdroj kopie');
    await page.locator('#orderNumber').fill('OBJ-7');
    await fillLine(page, 'Hosting 10/2026', '500');
    await save(page);
    const source = await openSaved(page);
    const number = await page.locator('#number').inputValue();

    await page.goto(`/invoices?q=${encodeURIComponent('Zdroj kopie')}`);
    const row = page.locator('tbody tr').first();
    await row.getByRole('button', { name: /Další akce/ }).click();
    await row.getByRole('menuitem', { name: 'Kopírovat' }).click();
    await expect(page).toHaveURL(/\/copy$/);
    await expect(page.locator('form#invoice-form')).toBeVisible();
    await expect(page.locator('[data-line-dates]')).toBeVisible();
    await expect(page.locator('#orderNumber')).toHaveValue('');
    await expect(page.locator('#description')).toHaveValue('Zdroj kopie');
    await expect(page.locator('#customer')).toHaveValue('Example Domestic a.s.');
    await expect(page.locator('[data-customer-summary]')).toBeVisible();
    await expect(page.locator('#lines-0-text')).toHaveValue('Hosting 10/2026');
    const copyNumber = await page.locator('#number').inputValue();
    expect(copyNumber).not.toBe(number);
    await expectNoSeriousViolations(page);

    // Nothing exists until Save.
    await page.goto(`/invoices?q=${encodeURIComponent('Zdroj kopie')}`);
    await expect(page.locator('tbody tr')).toHaveCount(1);

    await page.goBack();
    await expect(page.locator('form#invoice-form')).toBeVisible();
    await save(page);
    await expect(page).toHaveURL(/\/invoices$/);
    await expect(page.locator('[role=status]').filter({ hasText: `Faktura ${copyNumber} je uložená.` })).toBeVisible();

    await page.goto(`/invoices?q=${encodeURIComponent('Zdroj kopie')}`);
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await expect(page.getByRole('link', { name: copyNumber, exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: number, exact: true })).toBeVisible();

    await page.goto(source);
    await expect(page.locator('#number')).toHaveValue(number);
    await expect(page.locator('#orderNumber')).toHaveValue('OBJ-7');
  });

  test('copies a document in the trash', async ({ page }) => {
    await page.goto('/invoices?q=Zdroj%20kopie');
    const row = page.locator('tbody tr').first();
    await row.getByRole('button', { name: /Další akce/ }).click();
    await row.getByRole('menuitem', { name: 'Smazat' }).click();
    await page.getByRole('textbox', { name: /Důvod/ }).fill('Duplicita');
    await page.getByRole('button', { name: 'Přesunout do koše' }).click();
    await expect(page.getByText(/je v koši\./)).toBeVisible();

    await page.goto('/invoices?tab=trash');
    const trashed = page.locator('tbody tr').first();
    await trashed.getByRole('button', { name: /Další akce/ }).click();
    await trashed.getByRole('menuitem', { name: 'Kopírovat' }).click();
    await expect(page).toHaveURL(/\/copy$/);
    await expect(page.locator('#description')).toHaveValue('Zdroj kopie');
  });
});
