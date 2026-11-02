// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { INVOICING_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
// Set it to a folder to save screenshots of the screen for review. They are not baselines.
const SHOTS = process.env.MAIL_SHOTS;

test.use({ storageState: INVOICING_OWNER_STATE });

async function openMailSettings(page: Page, firm: string): Promise<void> {
  await page.goto('/settings/firms');
  await page.getByRole('link', { name: firm, exact: true }).click();
  await page.getByRole('navigation', { name: 'Části firmy' }).getByRole('link', { name: 'E-maily' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'E-maily' })).toBeVisible();
}

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

async function save(page: Page): Promise<void> {
  await page.locator('[data-action-bar]').getByRole('button', { name: 'Uložit' }).click();
}

test.describe.serial('the e-mail settings of a firm', () => {
  test('insert variables at the caret from the chip toolbar, undo one, and keep the text after a reload', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    const body = page.locator('#body-cs');
    await body.fill('Zasílám fakturu .');
    await body.press('End');
    await body.press('ArrowLeft');

    // From the body, the next Tab stop is the chip toolbar of the same language, at its first chip.
    await body.press('Tab');
    const toolbar = page.getByRole('toolbar', { name: 'Proměnné Čeština' });
    await expect(toolbar.getByRole('button', { name: 'Číslo dokladu' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(body).toHaveValue('Zasílám fakturu {Číslo dokladu}.');

    // The arrow keys move within the toolbar; Tab would leave it.
    await page.keyboard.press('ArrowRight');
    await expect(toolbar.getByRole('button', { name: 'Číslo objednávky' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(body).toHaveValue('Zasílám fakturu {Číslo dokladu}{Číslo objednávky}.');
    await expect(toolbar.getByRole('button', { name: 'Číslo objednávky' })).toBeFocused();

    // The browser's own undo takes the last insert back.
    await body.focus();
    await page.keyboard.press('ControlOrMeta+z');
    await expect(body).toHaveValue('Zasílám fakturu {Číslo dokladu}.');

    await save(page);
    await expect(page.getByText('E-mailové šablony jsou uložené.')).toBeVisible();
    await page.reload();
    await expect(page.locator('#body-cs')).toHaveValue('Zasílám fakturu {Číslo dokladu}.');
    await expect(page.getByRole('button', { name: 'Obnovit výchozí text' })).toBeVisible();
  });

  for (const code of ['cs', 'en']) {
    test(`Enter in the ${code} subject saves and never resets the saved text`, async ({ page }) => {
      await openMailSettings(page, 'Example Small Trader');
      await expect(page.getByRole('button', { name: 'Obnovit výchozí text' })).toBeVisible();
      page.on('dialog', (dialog) => {
        throw new Error(`Enter asked to confirm: ${dialog.message()}`);
      });
      const confirm = page.getByRole('alertdialog');
      const subject = page.locator(`#subject-${code}`);
      await subject.fill(code === 'cs' ? 'Faktura {Číslo dokladu} k úhradě' : 'Invoice {Document number} to pay');

      await subject.press('Enter');

      await expect(page.getByText('E-mailové šablony jsou uložené.')).toBeVisible();
      await expect(confirm).toBeHidden();
      await expect(page.locator(`#subject-${code}`)).toHaveValue(code === 'cs' ? 'Faktura {Číslo dokladu} k úhradě' : 'Invoice {Document number} to pay');
      await expect(page.locator('#body-cs')).toHaveValue('Zasílám fakturu {Číslo dokladu}.');
    });
  }

  test('names an unknown variable and keeps the typed text', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    await page.locator('#body-en').fill('Pay {amount} now');
    await save(page);

    await expect(page.locator('#body-en-error')).toContainText('{amount}');
    await expect(page.locator('#body-en')).toHaveValue('Pay {amount} now');
  });

  test('restores the built-in text only after a confirmation', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    const reset = page.getByRole('button', { name: 'Obnovit výchozí text' }).first();

    const confirm = page.getByRole('alertdialog', { name: 'Vrátit text pro Čeština na výchozí?' });
    await reset.click();
    await expect(confirm).toContainText('Vlastní text se smaže.');
    await confirm.getByRole('button', { name: 'Ponechat vlastní text' }).click();
    await expect(confirm).toBeHidden();
    await expect(page.locator('#body-cs')).toHaveValue('Zasílám fakturu {Číslo dokladu}.');

    await reset.click();
    await confirm.getByRole('button', { name: 'Obnovit výchozí text' }).click();
    await expect(page.getByText('Šablona má zase výchozí text.')).toBeVisible();
    await expect(page.locator('#subject-cs')).toHaveValue('Faktura {Číslo dokladu}');
    await expect(page.locator('#body-cs')).toHaveValue(/^Na základě Vaší objednávky/);
  });

  test('saves a server, presets the port, keeps a blank password and sends a test to the owner', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    await page.getByRole('link', { name: 'Poštovní server' }).click();
    await expect(page.getByText('Zkušební e-mail půjde poslat po uložení serveru.')).toBeVisible();

    await page.locator('#host').fill('mail.example.com');
    await page.locator('#encryption').selectOption('tls');
    await expect(page.locator('#port')).toHaveValue('465');
    await page.locator('#encryption').selectOption('starttls');
    await expect(page.locator('#port')).toHaveValue('587');
    await page.locator('#encryption').selectOption('tls');
    await page.locator('#username').fill('trader@example.com');
    await page.locator('#password').fill('throwaway-smtp-password');
    await page.locator('#fromAddress').fill('trader@example.com');
    await save(page);
    await expect(page.getByText('Poštovní server je uložený.')).toBeVisible();

    await page.reload();
    await expect(page.locator('#host')).toHaveValue('mail.example.com');
    await expect(page.locator('#port')).toHaveValue('465');
    await expect(page.locator('#password')).toHaveValue('');
    await expect(page.getByText('Prázdné pole ponechá uložené heslo.')).toBeVisible();

    // Saved again with the password left blank: accepted, and the stored one still sends.
    await page.locator('#fromName').fill('Example Small Trader, invoices');
    await save(page);
    await expect(page.getByText('Poštovní server je uložený.')).toBeVisible();
    await expect(page.locator('#password-error')).toHaveCount(0);
    await expect(page.locator('#fromName')).toHaveValue('Example Small Trader, invoices');
    await expect(page.getByText('Nastavení zatím nebylo ověřeno.')).toBeVisible();

    await page.getByRole('button', { name: 'Poslat zkušební e-mail' }).click();
    await expect(page.getByText('Zkušební e-mail odešel na invoicing@example.com.')).toBeVisible();
    await expect(page.locator('#mail-test-result')).toContainText(/Ověřeno \d/);
  });

  test('switches reminders on and sets their days with the keyboard, with no serious violation at either width', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    const reminder = page.getByRole('toolbar', { name: 'Proměnné Upomínka Čeština' });
    await expect(reminder.getByRole('button', { name: 'Zbývá uhradit' })).toBeVisible();
    await expect(page.getByText('Nejvýš 10 upomínek k jedné faktuře.')).toBeVisible();
    await expect(page.getByText('Upomínky jsou vypnuté.')).toBeVisible();

    // From the last reminder text, Tab passes its chips once and reaches the switch.
    await page.locator('#reminder-body-en').focus();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('toolbar', { name: 'Proměnné Upomínka Angličtina' }).getByRole('button', { name: 'Document number' })).toBeFocused();
    await page.keyboard.press('Tab');
    const toggle = page.getByRole('switch', { name: 'Posílat upomínky automaticky' });
    await expect(toggle).toBeFocused();
    await page.keyboard.press('Space');
    await expect(toggle).toBeChecked();

    await page.keyboard.press('Tab');
    await expect(page.locator('#reminders-first')).toBeFocused();
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('10');
    await page.keyboard.press('Tab');
    await expect(page.locator('#reminders-every')).toBeFocused();
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('5');
    await page.keyboard.press('Tab');
    await expect(page.locator('#reminders-minimum')).toBeFocused();
    await page.keyboard.type('500,50');
    await page.keyboard.press('Enter');

    await expect(page.getByText('E-mailové šablony jsou uložené.')).toBeVisible();
    await expect(page.getByRole('switch', { name: 'Posílat upomínky automaticky' })).toBeChecked();
    await expect(page.locator('#reminders-first')).toHaveValue('10');
    await expect(page.locator('#reminders-every')).toHaveValue('5');
    await expect(page.locator('#reminders-minimum')).toHaveValue('500,50');
    await expect(page.getByText('Zítra nepůjde žádná upomínka.')).toBeVisible();

    // A day out of range is named at its field, and nothing else changes.
    await page.locator('#reminders-every').fill('400');
    await save(page);
    await expect(page.locator('#reminders-every-error')).toContainText('od 1 do 365');
    await expect(page.locator('#reminders-every')).toHaveAttribute('aria-invalid', 'true');

    await page.reload();
    await page.locator('#reminders-card').scrollIntoViewIfNeeded();
    await expectNoSeriousViolations(page);
    if (SHOTS) {
      await page.locator('#reminders-card').screenshot({ path: `${SHOTS}/desktop-reminders-card.png` });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await expectNoSidewaysScroll(page);
    await expectNoSeriousViolations(page);
    if (SHOTS) {
      await page.locator('#reminders-card').screenshot({ path: `${SHOTS}/phone-reminders-card.png` });
    }
  });
});
