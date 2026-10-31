// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { OWNER_STATE } from '../support/session';

test.use({ storageState: OWNER_STATE });

test('tells the user when the register lookup is rate limited', async ({ page }) => {
  // The limit is 30 lookups a minute per user; the answer is simulated rather than provoked.
  await page.route('**/contacts/lookup?*', (route) => route.fulfill({
    status: 429,
    contentType: 'application/json',
    headers: { 'Retry-After': '60' },
    body: JSON.stringify({ error: 'rate_limited' }),
  }));

  await page.goto('/contacts/new');
  await page.locator('#lookup').fill('12345687');

  await expect(page.getByText('Příliš mnoho hledání. Zkuste to za minutu.')).toBeVisible();
});
