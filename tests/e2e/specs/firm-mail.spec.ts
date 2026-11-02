// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { INVOICING_OWNER_STATE } from '../support/session';

test.use({ storageState: INVOICING_OWNER_STATE });

async function openMailSettings(page: Page, firm: string): Promise<void> {
  await page.goto('/settings/firms');
  await page.getByRole('link', { name: firm, exact: true }).click();
  await page.getByRole('navigation', { name: 'Části firmy' }).getByRole('link', { name: 'E-maily' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'E-maily' })).toBeVisible();
}

test.describe.serial('the e-mail settings of a firm', () => {
  test('insert a variable at the caret with the keyboard, and keep it after a reload', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    const body = page.locator('#body-cs');
    await body.fill('Zasílám fakturu .');
    await body.press('End');
    await body.press('ArrowLeft');

    // From the body, the next stop is the first chip of the same language.
    await body.press('Tab');
    const chip = page.getByRole('group', { name: 'Proměnné' }).first().getByRole('button', { name: 'Číslo dokladu' });
    await expect(chip).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(body).toHaveValue('Zasílám fakturu {number}.');
    await expect(chip).toBeFocused();
    await page.keyboard.press('Tab');
    await page.keyboard.press('Enter');
    await expect(body).toHaveValue('Zasílám fakturu {number}{order_number}.');
    await page.locator('[data-action-bar]').getByRole('button', { name: 'Uložit' }).click();
    await expect(page.getByText('E-mailové šablony jsou uložené.')).toBeVisible();

    await page.reload();
    await expect(page.locator('#body-cs')).toHaveValue('Zasílám fakturu {number}{order_number}.');
    await expect(page.getByRole('button', { name: 'Obnovit výchozí text' })).toBeVisible();
  });

  test('names an unknown variable and keeps the typed text', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    await page.locator('#body-en').fill('Pay {amount} now');
    await page.locator('[data-action-bar]').getByRole('button', { name: 'Uložit' }).click();

    await expect(page.locator('#body-en-error')).toContainText('{amount}');
    await expect(page.locator('#body-en')).toHaveValue('Pay {amount} now');
  });

  test('restores the built-in text', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    await page.getByRole('button', { name: 'Obnovit výchozí text' }).click();

    await expect(page.getByText('Šablona má zase výchozí text.')).toBeVisible();
    await expect(page.locator('#subject-cs')).toHaveValue('Faktura {number}');
    await expect(page.locator('#body-cs')).toHaveValue(/^Na základě Vaší objednávky/);
  });

  test('saves a server, keeps a blank password and sends a test to the owner', async ({ page }) => {
    await openMailSettings(page, 'Example Small Trader');
    await page.getByRole('link', { name: 'Poštovní server' }).click();
    await expect(page.getByText('Zkušební e-mail půjde poslat po uložení serveru.')).toBeVisible();

    await page.locator('#host').fill('mail.example.com');
    await page.locator('#port').selectOption('465');
    await page.locator('#encryption').selectOption('tls');
    await page.locator('#username').fill('trader@example.com');
    await page.locator('#password').fill('throwaway-smtp-password');
    await page.locator('#fromAddress').fill('trader@example.com');
    await page.locator('[data-action-bar]').getByRole('button', { name: 'Uložit' }).click();
    await expect(page.getByText('Poštovní server je uložený.')).toBeVisible();

    await page.reload();
    await expect(page.locator('#host')).toHaveValue('mail.example.com');
    await expect(page.locator('#port')).toHaveValue('465');
    await expect(page.locator('#password')).toHaveValue('');
    await expect(page.getByText('Nastavení zatím nebylo ověřeno.')).toBeVisible();

    await page.getByRole('button', { name: 'Poslat zkušební e-mail' }).click();
    await expect(page.getByText('Zkušební e-mail odešel na invoicing@example.com.')).toBeVisible();
    await expect(page.getByText(/^Ověřeno /)).toBeVisible();
  });
});
