// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { DIALOGS_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const PHONE = { width: 390, height: 844 };
const DESKTOP = { width: 1440, height: 960 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  // On a phone the action bar floats over whatever field the scroll leaves below it; in flow for the check.
  const style = await page.addStyleTag({ content: '[data-action-bar] { position: static !important; }' });
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  await style.evaluate((element) => element.remove());
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

const preview = (page: Page) => page.locator('turbo-frame#recurring-preview');
const allDates = (page: Page) => page.getByRole('dialog', { name: 'Přehled budoucích vystavení' });

// The dates after the next one: the link opens them in a dialog, Zavřít closes it.
async function expectAllDates(page: Page, dates: string[]): Promise<void> {
  await preview(page).getByText('Přehled budoucích vystavení').click();
  await expect(allDates(page)).toBeVisible();
  await expect(allDates(page).locator('[data-preview-issue]')).toHaveText(dates);
  await allDates(page).getByRole('button', { name: 'Zavřít' }).last().click();
  await expect(allDates(page)).toBeHidden();
}

// Any invoice of the list will do: the dialog specs before this one send and trash some of them.
async function openFromInvoice(page: Page): Promise<void> {
  await page.goto('/invoices');
  const row = page.locator('tbody tr').first();
  await row.getByRole('button', { name: /Další akce/ }).click();
  await row.getByRole('menuitem', { name: 'Pravidelná faktura' }).click();
  await expect(page).toHaveURL(/\/invoices\/recurring\/new\?from=[0-9a-f-]+$/);
  await expect(page.locator('form#recurring-form')).toBeVisible();
}

test.describe.serial('a recurring invoice', () => {
  test.use({ storageState: DIALOGS_OWNER_STATE });

  test('starts from an invoice, previews the next issues and saves', async ({ page }) => {
    await openFromInvoice(page);
    await expect(page.locator('#customer')).toHaveValue('Example Buyer a.s.');
    const text = await page.locator('#lines-0-text').inputValue();
    // The seeded invoices, or the one the dialog specs issue.
    expect(['Support', 'Hosting', 'Licence', 'Konzultace']).toContain(text);
    await expect(page.locator('#description')).toHaveValue(text);
    await expect(page.locator('#name')).toHaveValue(text);
    await page.locator('#name').fill('Example yearly hosting');
    // A first issue today: issued on save after 5:00, by the run before it.
    await expect(preview(page).locator('[data-preview-next]')).toHaveText(/^Další faktura bude vystavena (hned po uložení|dnes v 5:00)$/);

    await page.locator('#firstIssueOn').fill('2027-01-31');
    await expect(preview(page).locator('[data-preview-next]')).toHaveText('Další faktura bude vystavena 31. 1. 2027');
    await expect(preview(page).locator('[data-preview-next]')).toHaveCount(1);
    await expectAllDates(page, ['31. 1. 2027', '28. 2. 2027', '31. 3. 2027', '30. 4. 2027', '31. 5. 2027']);

    await page.locator('#unit').selectOption('year');
    await expect(preview(page).locator('[data-preview-issue]').nth(1)).toHaveText('31. 1. 2028');
    await expect(preview(page).locator('[data-preview-description]')).toHaveCount(0);

    await page.locator('#description').fill('Hosting ');
    await page.locator('#description').press('End');
    await page.getByRole('toolbar', { name: 'Proměnné' }).getByRole('button', { name: 'Měsíc/rok' }).click();
    await expect(page.locator('#description')).toHaveValue('Hosting {month/year}');
    await expect(preview(page).locator('[data-preview-description]').first()).toHaveText('Hosting 1/2027');
    await preview(page).getByText('Přehled budoucích vystavení').click();
    await expect(allDates(page).locator('[data-preview-description]').nth(1)).toHaveText('Hosting 1/2028');
    await allDates(page).getByRole('button', { name: 'Zavřít' }).last().click();

    await page.locator('#end').selectOption('after_count');
    await expect(page.locator('#endAfter')).toBeVisible();
    await page.locator('#endAfter').fill('2');
    await expect(preview(page).locator('[data-preview-issue]')).toHaveCount(2);
    await preview(page).getByText('Přehled budoucích vystavení').click();
    await expect(allDates(page).locator('[data-preview-end]')).toBeVisible();
    await allDates(page).getByRole('button', { name: 'Zavřít' }).last().click();

    await page.locator('#recurring-save').click();
    await expect(page).toHaveURL(/\/invoices\/recurring$/);
    await expect(page.locator('[role=status]').filter({ hasText: 'Pravidelná faktura je uložená. Další faktura se vystaví 31. 1. 2027.' })).toBeVisible();

    await page.locator('tbody a[data-row-link]').filter({ hasText: 'Example yearly hosting' }).first().click();
    await expect(page).toHaveURL(/\/invoices\/recurring\/[0-9a-f-]+\/edit$/);
    await expect(page.locator('#name')).toHaveValue('Example yearly hosting');
    await expect(page.locator('#unit')).toHaveValue('year');
    await expect(page.locator('#firstIssueOn')).toHaveValue('2027-01-31');
    await expect(page.locator('#description')).toHaveValue('Hosting {month/year}');
    await expect(page.locator('#endAfter')).toHaveValue('2');
    await expect(page.locator('#customer')).toHaveValue('Example Buyer a.s.');
  });

  test('goes through the schedule card in order with the keyboard', async ({ page }) => {
    await page.goto('/invoices/recurring/new');
    await expect(page.locator('#onLastDayOfMonth')).toBeVisible();
    await page.locator('#unit').focus();
    // The − and + of "Opakovat každý" stay out of the way: the field takes the number typed.
    await tabThrough(page, ['firstIssueOn', 'onLastDayOfMonth', 'every', 'recurring-all-link', 'end']);

    await page.locator('#unit').selectOption('week');
    await expect(page.locator('#onLastDayOfMonth')).toBeHidden();
    await page.locator('#end').focus();
    await page.locator('#end').selectOption('on_date');
    await tabThrough(page, ['endOn']);
  });

  test('says what is wrong and keeps what was typed', async ({ page }) => {
    await page.goto('/invoices/recurring/new');
    await page.locator('#every').fill('0');
    await expect(preview(page).locator('[data-preview-problem]')).toContainText('Opakovat každý');
    await page.locator('#recurring-save').click();
    await expect(page.locator('[data-error-summary]')).toBeVisible();
    await expect(page.locator('#every')).toHaveValue('0');
    await expect(page.locator('#every')).toHaveAttribute('aria-invalid', 'true');
  });

  test('steps the numbers and says whom the invoice goes to', async ({ page }) => {
    await openFromInvoice(page);
    await page.locator('#firstIssueOn').fill('2027-01-31');
    await expect(preview(page).locator('[data-preview-next]')).toHaveText('Další faktura bude vystavena 31. 1. 2027');
    await page.getByRole('button', { name: 'Zvýšit: Opakovat každý' }).click();
    await expect(page.locator('#every')).toHaveValue('2');
    await expect(preview(page).locator('[data-preview-issue]').nth(1)).toHaveText('31. 3. 2027');
    const due = Number(await page.locator('#dueDays').inputValue());
    await page.getByRole('button', { name: 'Snížit: Splatnost (dní)' }).click();
    await expect(page.locator('#dueDays')).toHaveValue(String(due - 1));

    const sending = page.getByRole('group', { name: 'Po vystavení odeslat:' });
    await expect(sending.getByRole('checkbox', { name: 'Odběrateli' })).toBeChecked();
    await expect(page.locator('#sendToCustomer-address')).toHaveText(/@example\.com/);
    await expect(page.locator('#recurring-held')).toBeHidden();
    await sending.getByRole('checkbox', { name: 'Odběrateli' }).uncheck();
    await expect(page.locator('#recurring-held')).toHaveText('Faktura se jen vystaví a počká v seznamu K odeslání.');
    await expect(preview(page).locator('[data-preview-next]')).toHaveText('Další faktura bude vystavena 31. 1. 2027');

    // A new one has no customer yet: nobody to send to.
    await page.goto('/invoices/recurring/new');
    await expect(page.locator('#sendToCustomer-address')).toHaveText('e-mail není vyplněn');
    await expect(page.locator('#copyToOthers-address')).toHaveText('e-mail není vyplněn');
    // A box with no address cannot be ticked.
    for (const box of ['Odběrateli', 'Dalším příjemcům na kontaktu']) {
      await expect(sending.getByRole('checkbox', { name: box })).toBeDisabled();
      await expect(sending.getByRole('checkbox', { name: box })).not.toBeChecked();
    }
    await expect(page.locator('#recurring-held')).toBeVisible();
  });

  for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
    test(`is accessible and fits the ${name}`, async ({ page }) => {
      await page.setViewportSize(size);
      await openFromInvoice(page);
      await page.locator('#firstIssueOn').fill('2026-12-31');
      await page.locator('#onLastDayOfMonth').check();
      await expect(preview(page).locator('[data-preview-issue]').nth(1)).toHaveText('31. 1. 2027');
      await expectNoSidewaysScroll(page);
      await expectNoSeriousViolations(page);
      await page.screenshot({ path: `${SHOTS}/recurring-form-${name}.png`, fullPage: true });
      await page.locator('section').filter({ has: page.locator('#unit') }).screenshot({ path: `${SHOTS}/recurring-schedule-${name}.png` });
      await page.locator('section').filter({ has: page.locator('#sendToCustomer') }).screenshot({ path: `${SHOTS}/recurring-sending-${name}.png` });
      await page.locator('section').filter({ has: page.locator('#dueDays') }).screenshot({ path: `${SHOTS}/recurring-dates-${name}.png` });
      await page.locator('section').filter({ has: page.locator('#paymentMethod') }).screenshot({ path: `${SHOTS}/recurring-payment-${name}.png` });

      await preview(page).getByText('Přehled budoucích vystavení').click();
      await expect(allDates(page).locator('[data-preview-issue]')).toHaveCount(5);
      await expectNoSeriousViolations(page);
      await page.screenshot({ path: `${SHOTS}/recurring-all-dates-${name}.png` });
    });
  }
});
