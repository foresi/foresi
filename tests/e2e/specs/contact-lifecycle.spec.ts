// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { OWNER_STATE } from '../support/session';
import { openMoreActions } from '../support/header';

// This spec changes data. It runs last (see the "lifecycle" project), so the read-only
// specs and the screenshots always see the freshly seeded address book.
test.use({ storageState: OWNER_STATE });

const COMPANY = 'Example Company s.r.o.';

async function openContact(page: Page, name: string): Promise<void> {
  await page.goto('/contacts');
  await page.getByRole('link', { name, exact: true }).click();
  // The row opens the contact in the side sheet; the page is a click further.
  await page.locator('dialog#sheet').getByRole('link', { name: 'Otevřít jako stránku' }).click();
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
}

test.describe('contact life cycle', () => {
  test.describe.configure({ mode: 'serial' });

  test('creates a contact from a register (ARES autofill)', async ({ page }) => {
    await page.goto('/contacts/new');
    await page.locator('#lookup').fill('12345687');
    await page.getByRole('button', { name: new RegExp(COMPANY) }).click();

    // The register filled the form.
    await expect(page.locator('#displayName')).toHaveValue(COMPANY);
    await expect(page.locator('#registrationNumber')).toHaveValue('12345687');
    await expect(page.locator('#vatId')).toHaveValue('CZ12345687');
    await expect(page.locator('#street')).not.toHaveValue('');

    await page.getByRole('button', { name: 'Uložit' }).click();
    await expect(page.getByRole('heading', { level: 1, name: COMPANY })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Kontakt je uložen.' })).toBeVisible();
    await expect(page.locator('[data-party-summary]')).toContainText('Firma');
  });

  test('views and edits the contact', async ({ page }) => {
    await openContact(page, COMPANY);
    await expect(page.getByText('12345687').first()).toBeVisible();

    await page.getByRole('link', { name: 'Upravit' }).click();
    await page.locator('#phone').fill('+420 000 000 999');
    await page.getByRole('button', { name: 'Uložit' }).click();

    await expect(page.getByRole('status').filter({ hasText: 'Změny jsou uloženy.' })).toBeVisible();
    await expect(page.getByText('+420 000 000 999')).toBeVisible();

    // Every change is in the history.
    await page.getByRole('link', { name: 'Historie' }).click();
    await expect(page.locator('[data-party-history]')).toContainText('kontakt');
  });

  test('links a contact person', async ({ page }) => {
    await openContact(page, COMPANY);
    await page.getByRole('link', { name: 'Kontaktní osoby' }).click();
    await expect(page.getByText('Zatím tu není žádná kontaktní osoba.')).toBeVisible();

    // The tab adds in a dialog over the contact: a person from the address book, or a new one.
    await page.getByRole('link', { name: 'Přidat kontaktní osobu' }).click();
    const dialog = page.locator('dialog#dialog');
    await dialog.locator('#person').selectOption({ label: 'Alex Example' });
    await dialog.locator('#position').fill('Accountant');
    await dialog.getByRole('button', { name: 'Přidat osobu' }).click();

    await expect(page.getByRole('status').filter({ hasText: 'Kontaktní osoba je přidána.' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Alex Example' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Accountant' })).toBeVisible();
  });

  test('archives and restores the contact', async ({ page }) => {
    await openContact(page, COMPANY);

    await (await openMoreActions(page)).getByRole('menuitem', { name: 'Archivovat' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Kontakt je archivován.' })).toBeVisible();
    await expect(page.locator('[data-party-summary]')).toContainText('Archivováno');
    await expect((await openMoreActions(page)).getByRole('menuitem', { name: 'Obnovit' })).toBeVisible();

    await page.goto('/contacts?view=archived');
    await expect(page.getByRole('link', { name: COMPANY, exact: true })).toBeVisible();

    await openArchivedAndRestore(page);
    await page.goto('/contacts');
    await expect(page.getByRole('link', { name: COMPANY, exact: true })).toBeVisible();
  });
});

async function openArchivedAndRestore(page: Page): Promise<void> {
  await page.getByRole('link', { name: COMPANY, exact: true }).click();
  // The contact opens in the sheet; its header, not the list's, holds the restore.
  await expect(page.locator('dialog#sheet [data-more-actions-menu] [aria-haspopup="menu"]')).toBeVisible();
  await (await openMoreActions(page)).getByRole('menuitem', { name: 'Obnovit' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Kontakt je obnoven.' })).toBeVisible();
}
