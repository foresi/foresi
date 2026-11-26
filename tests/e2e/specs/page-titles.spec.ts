// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '../support/test';
import { OWNER_STATE } from '../support/session';

// The document title runs from the page to the product: "Page · Section · Foresi". The spec only reads.
test.describe('signed in', () => {
  test.use({ storageState: OWNER_STATE });

  test('a list page is its own section, so the title names it once', async ({ page }) => {
    await page.goto('/contacts');
    await expect(page).toHaveTitle('Adresář · Foresi');
  });

  test('a page inside a section names the section between the page and the product', async ({ page }) => {
    await page.goto('/contacts/new');
    await expect(page).toHaveTitle('Nový kontakt · Adresář · Foresi');
  });

  test('the title follows a Turbo visit from one section to another', async ({ page }) => {
    await page.goto('/projects');
    await expect(page).toHaveTitle('Projekty · Foresi');
    await page.locator('#sidebar').getByRole('link', { name: 'Úkoly' }).click();
    await expect(page).toHaveURL(/\/tasks$/);
    await expect(page).toHaveTitle('Úkoly · Foresi');
  });
});

test.describe('signed out', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('the login page has no section', async ({ page }) => {
    await page.goto('/login');
    await expect(page).toHaveTitle('Přihlášení · Foresi');
  });
});
