// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { expectFocusedClearOfActionBar } from '../support/layout';
import { OWNER_STATE } from '../support/session';

// A form that fails validation saves nothing, so this spec only reads data.
test.use({ storageState: OWNER_STATE });

const VIEWPORTS = { desktop: { width: 1440, height: 960 }, mobile: { width: 390, height: 844 } } as const;

for (const [name, viewport] of Object.entries(VIEWPORTS)) {
  test(`a contact sent back with errors marks every invalid field and focuses the first, clear of the action bar on ${name}`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.goto('/contacts/new');
    await expect(page.locator('main form')).toHaveAttribute('novalidate', '');

    // A sole trader needs no registration number, so the first problem is the street, far down the form.
    await page.locator('#kind').selectOption('sole_trader');
    await page.locator('#displayName').fill('Example Company');
    await page.locator('#email').fill('not-an-email');
    await page.locator('#city').fill('Example City');
    const save = page.getByRole('button', { name: 'Uložit' });
    await expect(save).toBeEnabled();
    await save.click();

    await expect(page).toHaveTitle(/^Chyba: Nový kontakt/);
    await expect(page.locator('main form [aria-invalid="true"]')).toHaveCount(3);
    for (const id of ['email', 'street', 'postalCode']) {
      await expect(page.locator(`#${id}`)).toHaveAttribute('aria-invalid', 'true');
      await expect(page.locator(`#${id}-error`)).toBeVisible();
    }
    // What was entered is kept.
    await expect(page.locator('#displayName')).toHaveValue('Example Company');
    await expect(page.locator('#email')).toHaveValue('not-an-email');
    await expect(page.locator('#city')).toHaveValue('Example City');
    // The focus is in the first invalid field, which is not hidden behind the floating bar.
    await expectFocusedClearOfActionBar(page, page.locator('#email'));
    await expect(save).toBeEnabled();
    await expect(page.locator('[data-action-bar-status]')).toHaveCount(0);

    // Fixing it and sending again moves on to the next problem, lower on the page.
    await page.locator('#email').fill('testing@example.com');
    await save.click();
    await expect(page.locator('main form [aria-invalid="true"]')).toHaveCount(2);
    await expectFocusedClearOfActionBar(page, page.locator('#street'));
  });
}
