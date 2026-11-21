// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/taxes.php: a sole trader without payments yet, and a team member.
const OWNER = 'taxes@example.com';
const MEMBER = 'taxes-member@example.com';

test.describe('taxes and contributions', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
  });

  test('the owner sees what they keep and opens the steps of social insurance', async ({ page }) => {
    await signIn(page, OWNER);
    await page.goto('/cash-flow');
    await page.locator('#sidebar').getByRole('link', { name: 'Daně a odvody' }).click();
    await expect(page).toHaveURL(/\/taxes-and-contributions$/);

    const summary = page.locator('[data-taxes-summary]');
    await expect(summary.getByRole('heading', { name: 'Kolik ti zůstane' })).toBeVisible();
    await expect(summary.getByRole('columnheader', { name: 'Zatím' })).toBeVisible();
    await expect(summary.getByRole('columnheader', { name: 'Na konci roku' })).toBeVisible();
    await expect(page.locator('[data-taxes-year-end-empty="forecast_not_built"]')).toContainText('předpověď z Cash flow');
    await expect(page.locator('[data-taxes-footer]')).toContainText('Odhad, ne podklad pro přiznání.');

    // A number opens its steps in the side sheet over the page, as a cell of Cash flow opens its items.
    await page.locator('[data-taxes-row="social"] a').click();
    const sheet = page.locator('dialog#sheet');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('heading', { name: 'Sociální pojištění · Skutečné výdaje' })).toBeVisible();
    await expect(sheet.locator('[data-taxes-step="social"] [data-taxes-step-rule]')).toContainText('Podíl ze zisku');
    await expect(sheet.getByRole('link', { name: /Zdroj pravidla/ })).toHaveAttribute('href', /^https:\/\//);

    await sheet.getByRole('button', { name: 'Zavřít' }).first().click();
    await expect(sheet).toBeHidden();
    await expect(page.locator('[data-taxes-summary]')).toBeVisible();
  });

  test('a team member does not see the menu item', async ({ page }) => {
    await signIn(page, MEMBER);
    await page.goto('/cash-flow');
    await expect(page.locator('#sidebar').getByRole('link', { name: 'Daně a odvody' })).toHaveCount(0);
    const response = await page.goto('/taxes-and-contributions');
    expect(response?.status()).toBe(403);
  });
});
