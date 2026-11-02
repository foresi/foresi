// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { BANK_OWNER_STATE } from '../support/session';

const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ADDRESS = /^bank\+[a-z2-7]{26}@example\.com$/;

test.use({ storageState: BANK_OWNER_STATE, permissions: ['clipboard-read', 'clipboard-write'] });

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

async function openBank(page: Page): Promise<void> {
  await page.goto('/settings/firms');
  await page.getByRole('link', { name: 'Example Bank Settings s.r.o.', exact: true }).click();
  await page.getByRole('navigation', { name: 'Části firmy' }).getByRole('link', { name: 'Banka' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Banka' })).toBeVisible();
}

test.describe.serial('the bank settings of a firm', () => {
  test('add an account by keyboard in the dialog, generate and copy its address, toggle a switch, and keep all after a reload', async ({ page }) => {
    await openBank(page);
    await expect(page.getByText('Zatím tu nejsou žádné účty')).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/firm-bank-empty.png`, fullPage: true });

    // Keyboard only: focus the button, open the dialog, fill it and send it with Enter.
    await page.locator('#bank-account-add').focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Nový bankovní účet' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#number')).toBeFocused();
    await page.keyboard.type('19-2000145399/0800');
    await page.keyboard.press('Tab');
    await expect(dialog.locator('#currency')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(dialog.locator('#label')).toBeFocused();
    await page.keyboard.type('Provozní účet');
    await expect(dialog.locator('#primary')).toBeChecked();
    await page.screenshot({ path: `${SHOTS}/firm-bank-account-dialog.png` });
    await expectNoSeriousViolations(page);
    await page.keyboard.press('Enter');
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Účet Provozní účet je přidaný.')).toBeVisible();
    const row = page.locator('tbody tr', { hasText: 'Provozní účet' });
    await expect(row).toContainText('CZ65 0800 0000 1920 0014 5399');
    await expect(row).toContainText('Hlavní');
    await expect(row).toContainText('Bez adresy');
    await page.screenshot({ path: `${SHOTS}/firm-bank-accounts.png`, fullPage: true });
    await expectNoSeriousViolations(page);

    await page.getByRole('navigation', { name: 'Části nastavení banky' }).getByRole('link', { name: 'Párování plateb' }).click();
    const feed = page.locator('[data-bank-feed]');
    await expect(feed).toContainText('Účet zatím nemá adresu.');
    await feed.getByRole('button', { name: 'Vygenerovat adresu' }).click();
    await expect(page.getByText('Adresa pro Provozní účet je připravená. Zadejte ji v bance.')).toBeVisible();
    const field = feed.getByLabel('Adresa pro e-maily z banky');
    await expect(field).toHaveValue(ADDRESS);
    const address = await field.inputValue();

    await feed.getByRole('button', { name: 'Kopírovat' }).click();
    await expect(feed.getByRole('button', { name: 'Zkopírováno' })).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(address);

    await page.locator('#smallDifference').check();
    await page.locator('[data-action-bar]').getByRole('button', { name: 'Uložit' }).click();
    await expect(page.getByText('Nastavení párování je uložené.')).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/firm-bank-pairing.png`, fullPage: true });
    await expectNoSeriousViolations(page);

    await page.reload();
    await expect(page.locator('#autoPairing')).toBeChecked();
    await expect(page.locator('#smallDifference')).toBeChecked();
    await expect(page.locator('[data-bank-feed]').getByLabel('Adresa pro e-maily z banky')).toHaveValue(address);
    await page.goto(page.url().replace(/\?tab=pairing$/, ''));
    await expect(page.locator('tbody tr', { hasText: 'Provozní účet' })).toContainText('Nastavená');
  });

  test('the address dialog shows the setup steps', async ({ page }) => {
    await openBank(page);
    await page.getByRole('navigation', { name: 'Části nastavení banky' }).getByRole('link', { name: 'Párování plateb' }).click();
    await page.getByRole('link', { name: 'Jak nastavit banku' }).click();
    const dialog = page.getByRole('dialog', { name: 'Adresa pro banku: Provozní účet' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Adresa pro e-maily z banky')).toHaveValue(ADDRESS);
    await expect(dialog.getByRole('heading', { name: 'MojeBanka Business' })).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'KB+' })).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/firm-bank-address-dialog.png` });
    await expectNoSeriousViolations(page);
    // The footer button; the header has its own close button of the same name.
    await dialog.getByRole('button', { name: 'Zavřít' }).last().click();
    await expect(dialog).toBeHidden();
  });

  test('regenerating asks first and replaces the address', async ({ page }) => {
    await openBank(page);
    await page.getByRole('navigation', { name: 'Části nastavení banky' }).getByRole('link', { name: 'Párování plateb' }).click();
    const field = page.locator('[data-bank-feed]').getByLabel('Adresa pro e-maily z banky');
    const old = await field.inputValue();

    await page.getByRole('button', { name: 'Vygenerovat novou' }).click();
    const confirm = page.getByRole('alertdialog', { name: 'Vygenerovat novou adresu pro Provozní účet?' });
    await expect(confirm).toContainText('Stará adresa přestane hned fungovat.');
    await page.screenshot({ path: `${SHOTS}/firm-bank-regenerate-confirm.png` });
    await confirm.getByRole('button', { name: 'Vygenerovat novou' }).click();

    await expect(page.getByText('Adresa pro Provozní účet je připravená. Zadejte ji v bance.')).toBeVisible();
    await expect(field).toHaveValue(ADDRESS);
    expect(await field.inputValue()).not.toBe(old);
  });

  test('fits a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openBank(page);
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/firm-bank-accounts-phone.png`, fullPage: true });
    await page.getByRole('row', { name: /Provozní účet/ }).getByRole('button', { name: /Další akce/ }).click();
    await page.getByRole('menuitem', { name: 'Upravit' }).click();
    const dialog = page.getByRole('dialog', { name: 'Upravit bankovní účet' });
    await expect(dialog).toBeVisible();
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/firm-bank-account-dialog-phone.png` });
    await dialog.getByRole('button', { name: 'Zrušit' }).click();
    await page.getByRole('navigation', { name: 'Části nastavení banky' }).getByRole('link', { name: 'Párování plateb' }).click();
    await expect(page.locator('#autoPairing')).toBeVisible();
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/firm-bank-pairing-phone.png`, fullPage: true });
    await page.getByRole('link', { name: 'Jak nastavit banku' }).click();
    await expect(page.getByRole('dialog', { name: /Adresa pro banku/ })).toBeVisible();
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/firm-bank-address-dialog-phone.png` });
  });
});
