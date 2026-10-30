// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { OWNER_STATE } from '../support/session';

// This spec changes data. It runs last (see the "lifecycle" project), so the read-only
// specs and the screenshots always see the freshly seeded address book.
test.use({ storageState: OWNER_STATE });

const COMPANY = 'Example Company s.r.o.';

async function openContact(page: Page, name: string): Promise<void> {
  await page.goto('/contacts');
  await page.getByRole('link', { name, exact: true }).click();
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

    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('heading', { level: 1, name: COMPANY })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'The contact is saved.' })).toBeVisible();
    await expect(page.locator('[data-party-summary]')).toContainText('Company');
  });

  test('views and edits the contact', async ({ page }) => {
    await openContact(page, COMPANY);
    await expect(page.getByText('12345687').first()).toBeVisible();

    await page.getByRole('link', { name: 'Edit' }).click();
    await page.locator('#phone').fill('+420 000 000 999');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('status').filter({ hasText: 'The changes are saved.' })).toBeVisible();
    await expect(page.getByText('+420 000 000 999')).toBeVisible();

    // Every change is in the history.
    await page.getByRole('link', { name: 'History' }).click();
    await expect(page.locator('[data-party-history]')).toContainText('contact');
  });

  test('links a contact person', async ({ page }) => {
    await openContact(page, COMPANY);
    await page.getByRole('link', { name: 'Contact persons' }).click();
    await expect(page.getByText('There is no contact person yet.')).toBeVisible();

    await page.locator('#link-person').selectOption({ label: 'Alex Example' });
    await page.locator('#link-position').fill('Accountant');
    await page.getByRole('button', { name: 'Add person' }).click();

    await expect(page.getByRole('status').filter({ hasText: 'The contact person is added.' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Alex Example' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Accountant' })).toBeVisible();
  });

  test('archives and restores the contact', async ({ page }) => {
    await openContact(page, COMPANY);

    await page.getByRole('button', { name: 'Archive' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'The contact is archived.' })).toBeVisible();
    await expect(page.locator('[data-party-summary]')).toContainText('Archived');
    await expect(page.getByRole('button', { name: 'Restore' })).toBeVisible();

    await page.goto('/contacts?view=archived');
    await expect(page.getByRole('link', { name: COMPANY, exact: true })).toBeVisible();

    await openArchivedAndRestore(page);
    await page.goto('/contacts');
    await expect(page.getByRole('link', { name: COMPANY, exact: true })).toBeVisible();
  });
});

async function openArchivedAndRestore(page: Page): Promise<void> {
  await page.getByRole('link', { name: COMPANY, exact: true }).click();
  await page.getByRole('button', { name: 'Restore' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'The contact is restored.' })).toBeVisible();
}
