// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { FIRM_MAIL_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
// Set it to a folder to save screenshots of the screen for review. They are not baselines.
const SHOTS = process.env.MAIL_SHOTS;

test.use({ storageState: FIRM_MAIL_OWNER_STATE });

async function openMailSettings(page: Page): Promise<void> {
  await page.goto('/settings/firm/mail');
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

async function openTemplate(page: Page, name: string): Promise<Locator> {
  await openMailSettings(page);
  await page.locator('#mail-templates').getByRole('link', { name, exact: true }).click();
  const sheet = page.getByRole('dialog', { name });
  await expect(sheet).toBeVisible();

  return sheet;
}

test.describe.serial('the e-mail settings of a firm', () => {
  test('lists the templates, and one opens in a sheet with a tab per language', async ({ page }) => {
    await openMailSettings(page);
    const list = page.locator('#mail-templates');
    await expect(list.getByRole('link')).toHaveText(['Faktura', 'Zálohová faktura', 'Daňový doklad k platbě', 'Opravný daňový doklad', 'Upomínka']);
    await expect(page.locator('#subject-cs')).toHaveCount(0);

    const sheet = await openTemplate(page, 'Faktura');
    const tabs = sheet.getByRole('tablist', { name: 'Jazyky šablony' });
    await expect(tabs.getByRole('tab', { name: 'Čeština' })).toHaveAttribute('aria-selected', 'true');
    await expect(sheet.locator('#subject-cs')).toBeVisible();
    await expect(sheet.locator('#subject-en')).toBeHidden();

    // Automatic activation: the arrow key shows the other language.
    await tabs.getByRole('tab', { name: 'Čeština' }).focus();
    await page.keyboard.press('ArrowRight');
    await expect(tabs.getByRole('tab', { name: 'Angličtina' })).toBeFocused();
    await expect(sheet.locator('#subject-en')).toBeVisible();
    await expect(sheet.locator('#subject-cs')).toBeHidden();

    await expectNoSeriousViolations(page);
    if (SHOTS) {
      await sheet.screenshot({ path: `${SHOTS}/desktop-template-sheet.png` });
    }
  });

  test('insert variables at the caret from the chip toolbar, undo one, preview and save', async ({ page }) => {
    const sheet = await openTemplate(page, 'Faktura');
    const body = sheet.locator('#body-cs');
    await body.fill('Zasílám **fakturu** .');
    await body.press('End');
    await body.press('ArrowLeft');

    // From the body, the next Tab stop is the chip toolbar of the same language, at its first chip.
    await body.press('Tab');
    const toolbar = sheet.getByRole('toolbar', { name: 'Proměnné Čeština' });
    await expect(toolbar.getByRole('button', { name: 'Číslo dokladu' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(body).toHaveValue('Zasílám **fakturu** {Číslo dokladu}.');

    // The arrow keys move within the toolbar; Tab would leave it.
    await page.keyboard.press('ArrowRight');
    await expect(toolbar.getByRole('button', { name: 'Číslo objednávky' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(body).toHaveValue('Zasílám **fakturu** {Číslo dokladu}{Číslo objednávky}.');

    // The browser's own undo takes the last insert back.
    await body.focus();
    await page.keyboard.press('ControlOrMeta+z');
    await expect(body).toHaveValue('Zasílám **fakturu** {Číslo dokladu}.');

    // The preview shows the mail as received: the subject, the Markdown rendered, made-up values.
    await sheet.getByRole('tab', { name: 'Náhled' }).first().click();
    const preview = sheet.locator('#body-cs-preview');
    await expect(preview.locator('[data-mail-preview-subject]')).toContainText('Faktura 20260042');
    await expect(preview.locator('strong')).toHaveText('fakturu');
    await expect(preview).toContainText('Zasílám fakturu 20260042.');
    await expect(preview.getByRole('link')).toHaveText('https://example.com/d/sample');

    await sheet.getByRole('button', { name: 'Uložit' }).click();
    await expect(page.getByText('Šablona „Faktura“ je uložená.')).toBeVisible();
    await expect(page.locator('[data-mail-template="invoice"]')).toContainText('Čeština: vlastní text');
    const again = await openTemplate(page, 'Faktura');
    await expect(again.locator('#body-cs')).toHaveValue('Zasílám **fakturu** {Číslo dokladu}.');
    await expect(again.getByRole('button', { name: 'Obnovit výchozí text' })).toBeVisible();
  });

  test('Enter in the subject saves and never resets the saved text', async ({ page }) => {
    const sheet = await openTemplate(page, 'Faktura');
    page.on('dialog', (dialog) => {
      throw new Error(`Enter asked to confirm: ${dialog.message()}`);
    });
    const subject = sheet.locator('#subject-cs');
    await subject.fill('Faktura {Číslo dokladu} k úhradě');

    await subject.press('Enter');

    await expect(page.getByText('Šablona „Faktura“ je uložená.')).toBeVisible();
    await expect(page.getByRole('alertdialog')).toBeHidden();
    const again = await openTemplate(page, 'Faktura');
    await expect(again.locator('#subject-cs')).toHaveValue('Faktura {Číslo dokladu} k úhradě');
    await expect(again.locator('#body-cs')).toHaveValue('Zasílám **fakturu** {Číslo dokladu}.');
  });

  test('names an unknown variable, shows its language and keeps the typed text', async ({ page }) => {
    const sheet = await openTemplate(page, 'Faktura');
    await sheet.getByRole('tab', { name: 'Angličtina' }).click();
    await sheet.locator('#body-en').fill('Pay {amount} now');
    await sheet.getByRole('tab', { name: 'Čeština' }).click();
    await sheet.getByRole('button', { name: 'Uložit' }).click();

    await expect(sheet.getByRole('tab', { name: /Angličtina/ })).toHaveAttribute('aria-selected', 'true');
    await expect(sheet.locator('#body-en-error')).toContainText('{amount}');
    await expect(sheet.locator('#body-en')).toHaveValue('Pay {amount} now');
  });

  test('restores the built-in text only after a confirmation', async ({ page }) => {
    const sheet = await openTemplate(page, 'Faktura');
    const reset = sheet.getByRole('button', { name: 'Obnovit výchozí text' });

    const confirm = page.getByRole('alertdialog', { name: 'Vrátit text pro Čeština na výchozí?' });
    await reset.click();
    await expect(confirm).toContainText('Vlastní text se smaže.');
    await confirm.getByRole('button', { name: 'Ponechat vlastní text' }).click();
    await expect(confirm).toBeHidden();
    await expect(sheet.locator('#body-cs')).toHaveValue('Zasílám **fakturu** {Číslo dokladu}.');

    await reset.click();
    await confirm.getByRole('button', { name: 'Obnovit výchozí text' }).click();
    await expect(page.getByText('Šablona má zase výchozí text.')).toBeVisible();
    await expect(page.locator('[data-mail-template="invoice"]')).toContainText('Čeština: výchozí text');
    const again = await openTemplate(page, 'Faktura');
    await expect(again.locator('#subject-cs')).toHaveValue('Faktura {Číslo dokladu} od {Název firmy}');
    await expect(again.locator('#body-cs')).toHaveValue(/^Dobrý den,\n\nv tomto e-mailu Vám posílám fakturu č\. \{Číslo dokladu\}\./);
  });

  test('saves a server, presets the port, keeps a blank password and sends a test to the owner', async ({ page }) => {
    await openMailSettings(page);
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
    await expect(page.getByText('Prázdné pole ponechá uložené heslo, pokud nezměníte server, port, šifrování ani uživatelské jméno.')).toBeVisible();

    // Saved again with the password left blank: accepted, and the stored one still sends.
    await page.locator('#fromName').fill('Example Small Trader, invoices');
    await save(page);
    await expect(page.getByText('Poštovní server je uložený.')).toBeVisible();
    await expect(page.locator('#password-error')).toHaveCount(0);
    await expect(page.locator('#fromName')).toHaveValue('Example Small Trader, invoices');
    await expect(page.getByText('Nastavení zatím nebylo ověřeno.')).toBeVisible();

    // A new host with the password left blank asks for it again, so it never goes to another server.
    await page.locator('#host').fill('smtp.example.com');
    await save(page);
    await expect(page.locator('#password-error')).toContainText('Zadejte heslo znovu.');
    await page.reload();
    await expect(page.locator('#host')).toHaveValue('mail.example.com');

    await page.getByRole('button', { name: 'Poslat zkušební e-mail' }).click();
    await expect(page.getByText('Zkušební e-mail odešel na firm-mail@example.com.')).toBeVisible();
    await expect(page.locator('#mail-test-result')).toContainText(/Ověřeno \d/);
  });

  test('switches reminders on and sets their days with the keyboard, with no serious violation at either width', async ({ page }) => {
    await openMailSettings(page);
    await expect(page.getByText('Nejvýš 10 upomínek k jedné faktuře.')).toBeVisible();
    await expect(page.getByText('Upomínky jsou vypnuté.')).toBeVisible();

    // From the last template in the list, Tab reaches the switch of the reminders card.
    await page.locator('#mail-templates').getByRole('link', { name: 'Upomínka', exact: true }).focus();
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

    await expect(page.getByText('Nastavení upomínek je uložené.')).toBeVisible();
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
