// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { OWNER_STATE } from '../support/session';

// This spec changes data, so it runs in the "lifecycle" project after the read-only specs.
// Turbo only renders a form answer that is a redirect or a 4xx: PHPUnit cannot see a duplicate
// warning that answered with 200 and stayed invisible, so the browser has to confirm it.
test.use({ storageState: OWNER_STATE });

test('warns about a duplicate and saves it anyway', async ({ page }) => {
  await page.goto('/contacts/new');
  await page.locator('#displayName').fill('Example Twin s.r.o.');
  // Same registration number as the seeded "Example Supplies s.r.o.".
  await page.locator('#registrationNumber').fill('12345695');
  await page.locator('#street').fill('Example Street 2');
  await page.locator('#city').fill('Example City');
  await page.locator('#postalCode').fill('100 00');

  await page.getByRole('button', { name: 'Uložit', exact: true }).click();

  const warning = page.locator('[data-duplicate-warning]');
  await expect(warning).toBeVisible();
  await expect(warning).toContainText('Takový kontakt už máte');
  await expect(warning.getByRole('link', { name: 'Example Supplies s.r.o.' })).toBeVisible();
  await expect(page).toHaveURL(/\/contacts\/new$/);

  await warning.getByRole('button', { name: 'Uložit i tak' }).click();

  await expect(page.getByRole('heading', { level: 1, name: 'Example Twin s.r.o.' })).toBeVisible();
  await expect(page.getByRole('status').filter({ hasText: 'Kontakt je uložen.' })).toBeVisible();
});
