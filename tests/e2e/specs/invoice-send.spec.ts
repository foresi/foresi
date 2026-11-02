// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { SENDING_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1440, height: 960 };
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

// The dialog of a seeded invoice, reached from the row menu of the list.
async function openFromList(page: Page, number: string): Promise<void> {
  await page.goto('/invoices');
  const target = row(page, number);
  await target.getByRole('button', { name: /Další akce/ }).click();
  await target.getByRole('menuitem', { name: 'Odeslat' }).click();
  await expect(page.getByRole('heading', { level: 1, name: `Odeslat fakturu ${number}` })).toBeVisible();
}

function row(page: Page, number: string) {
  return page.locator('tbody tr').filter({ has: page.getByRole('button', { name: `Další akce pro ${number}`, exact: true }) });
}

async function tabThrough(page: Page, ids: string[]): Promise<void> {
  for (const id of ids) {
    await page.keyboard.press('Tab');
    await expect(page.locator(`#${id}`)).toBeFocused();
  }
}

test.use({ storageState: SENDING_OWNER_STATE });

test.describe.serial('sending an invoice', () => {
  test('opens from the list filled in, at both widths @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(DESKTOP);
      await openFromList(page, '20260001');
      await page.setViewportSize(size);

      await expect(page.getByText('Example Sending s.r.o. <invoices@example.com>')).toBeVisible();
      await expect(page.getByRole('checkbox', { name: 'billing@example.com' })).toBeChecked();
      await expect(page.getByRole('checkbox', { name: 'accounts@example.com (kopie)' })).toBeChecked();
      await expect(page.getByRole('checkbox', { name: 'Poslat kopii i mně na sending@example.com' })).toBeChecked();
      await expect(page.locator('#subject')).toHaveValue('Faktura 20260001');
      await expect(page.locator('#body')).toHaveValue(/^Na základě Vaší objednávky Vám zasílám fakturu č\. 20260001 na částku 29\s040,00\sCZK/);
      await expect(page.locator('#body')).toHaveValue(/\{Odkaz na doklad\}$/);
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/send-dialog-${name}.png`, fullPage: true });
    }
  });

  test('a firm without a mail server explains and links to its settings @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(DESKTOP);
      await openFromList(page, 'Q20260001');
      await page.setViewportSize(size);

      await expect(page.getByRole('heading', { name: 'Firma nemá poštovní server' })).toBeVisible();
      await expect(page.locator('#subject')).toHaveCount(0);
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/send-no-server-${name}.png`, fullPage: true });
    }
    await page.getByRole('link', { name: 'Nastavit poštovní server' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'E-maily' })).toBeVisible();
    await expect(page.locator('#host')).toBeVisible();
  });

  test('a refusal keeps what was typed and puts the focus on what to fix @a11y', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(DESKTOP);
      await openFromList(page, '20260001');
      await page.setViewportSize(size);
      await page.locator('#others').fill('boss@example.com, not-an-address');
      await page.locator('#body').fill('Upravený text {Odkaz na doklad}');

      await page.getByRole('button', { name: 'Odeslat', exact: true }).click();

      await expect(page.locator('[data-error-summary]')).toContainText('Další příjemci: not-an-address není platná e-mailová adresa.');
      await expect(page.locator('#others')).toBeFocused();
      await expect(page.locator('#others')).toHaveValue('boss@example.com, not-an-address');
      await expect(page.locator('#body')).toHaveValue('Upravený text {Odkaz na doklad}');
      await expect(page).toHaveTitle(/^Chyba:/);
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/send-refused-${name}.png`, fullPage: true });
    }
  });

  test('the list marks how each invoice was sent, at both widths @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      const failed = page.locator('tbody tr').filter({ has: page.getByRole('button', { name: 'Další akce pro 20260002', exact: true }) });
      const read = page.locator('tbody tr').filter({ has: page.getByRole('button', { name: 'Další akce pro 20260003', exact: true }) });
      if (name === 'desktop') {
        await expect(failed.locator('[data-icon-cell]')).toHaveText('Neodešlo');
        await expect(failed.locator('[data-icon-cell]')).toHaveAttribute('title', 'E-mail se nepodařilo odeslat');
        await expect(read.locator('[data-icon-cell]')).toHaveText('Přečteno');
        await expect(row(page, '20260001').locator('[data-icon-cell]')).toHaveCount(0);
      } else {
        // On a phone the mark is part of the line under the number, label included.
        await expect(failed.locator('[data-row-secondary]')).toContainText('Neodešlo');
        await expect(read.locator('[data-row-secondary]')).toContainText('Přečteno');
      }
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/send-marks-${name}.png`, fullPage: true });
    }
  });

  test('a failed send raises a notice that leads to the log, the history and the dialog @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/contacts');
      const notice = page.locator('[data-shell-notices]');
      await expect(notice).toContainText('Fakturu se nepodařilo odeslat.');
      await notice.getByRole('link', { name: 'Zobrazit' }).click();

      await expect(page.getByRole('heading', { level: 1, name: 'Odeslané e-maily' })).toBeVisible();
      await expect(page.getByRole('navigation', { name: 'Stavy odeslání' }).getByRole('link', { name: 'Neodešlo' })).toHaveAttribute('aria-current', 'page');
      await expect(page.locator('tbody tr')).toHaveCount(1);
      await expect(page.locator('tbody tr')).toContainText('SMTP 550');
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/send-log-${name}.png`, fullPage: true });
    }

    await page.setViewportSize(DESKTOP);
    await page.locator('tbody tr').getByRole('link', { name: '20260002' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Historie faktury 20260002' })).toBeVisible();
    const entry = page.locator('[data-send-entry]');
    await expect(entry).toContainText('refuse@example.com');
    await expect(entry).toContainText('SMTP 550');
    await entry.getByText('Zobrazit e-mail').click();
    await expect(entry).toContainText('posíláme fakturu 20260002');
    await expectNoSeriousViolations(page);
    await page.screenshot({ path: `${SHOTS}/send-history-desktop.png`, fullPage: true });
    await page.setViewportSize(PHONE);
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/send-history-phone.png`, fullPage: true });
    await page.setViewportSize(DESKTOP);

    const history = page.url();
    const [pdf] = await Promise.all([page.waitForResponse((response) => /\/sends\/[0-9a-f-]+\/pdf$/.test(response.url())), entry.getByRole('link', { name: 'PDF, jak odešlo' }).click()]);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toBe('application/pdf');
    await page.goto(history);

    await page.locator('[data-send-entry]').getByRole('link', { name: 'Odeslat znovu' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Odeslat fakturu 20260002' })).toBeVisible();
  });

  test('send and save from the form, then send from the keyboard', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/invoices/new');
    await expect(page.locator('form#invoice-form')).toBeVisible();
    const firm = page.locator('select[name=firmId]');
    if ((await firm.locator('option:checked').textContent()) !== 'Example Sending s.r.o.') {
      await firm.selectOption({ label: 'Example Sending s.r.o.' });
      await expect(page.locator('select[data-cell=vat]').first()).toBeVisible();
    }
    await page.locator('#customer').fill('Example Recip');
    await page.getByRole('option', { name: 'Example Recipient a.s.' }).click();
    await expect(page.locator('[data-customer-summary]')).toBeVisible();
    await page.locator('#lines-0-text').fill('Konzultace');
    await page.locator('#lines-0-price').fill('1500');

    await page.locator('#invoice-more').click();
    await expect(page.getByRole('menuitem').first()).toHaveText('Odeslat a uložit');
    await page.getByRole('menuitem', { name: 'Odeslat a uložit' }).click();

    await expect(page.getByRole('heading', { level: 1, name: 'Odeslat fakturu 20260004' })).toBeVisible();
    await expect(page.getByText('Faktura 20260004 je uložená.')).toBeVisible();

    // Recipients, the copy, subject, body, then Back and Send.
    await page.locator('#recipient-1').focus();
    await tabThrough(page, ['recipient-2', 'others', 'copyToMe', 'subject', 'body']);
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Zpět' })).toBeFocused();
    await tabThrough(page, ['send-submit']);
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/invoices$/);
    await expect(page.getByText('Faktura 20260004 je zařazená k odeslání na billing@example.com, accounts@example.com.')).toBeVisible();
    // The e2e queue delivers at once: the recording gateway accepted it, which marks the invoice delivered.
    const sent = page.locator('tbody tr').filter({ has: page.getByRole('button', { name: 'Další akce pro 20260004', exact: true }) });
    await expect(sent).toContainText('Doručeno');
    await expect(sent.locator('[data-icon-cell]')).toHaveText('Odesláno');
    await page.screenshot({ path: `${SHOTS}/send-queued-desktop.png`, fullPage: true });

    // Sent once: a second look at the dialog says so.
    await openFromList(page, '20260004');
    await expect(page.locator('[data-sent-before]')).toContainText('Faktura už odešla');
  });
});
