// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { OWNER, signIn } from '../support/session';

// These specs start signed out.
test.use({ storageState: { cookies: [], origins: [] } });

test('a visitor is sent to the sign-in page', async ({ page }) => {
  await page.goto('/contacts');
  await expect(page).toHaveURL(/\/login$/);
});

test('signing in with a wrong password shows an error and stays on the page', async ({ page }) => {
  await page.goto('/login');
  await page.locator('#username').fill(OWNER);
  await page.locator('#password').fill('not-the-password');
  await page.locator('form button[type=submit]').click();
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
});

test('an owner signs in and signs out again', async ({ page }) => {
  await signIn(page);
  await expect(page.locator('#user-menu-button')).toContainText(OWNER);

  await page.locator('#user-menu-button').click();
  await page.getByRole('menuitem', { name: 'Odhlásit se' }).click();

  await expect(page).toHaveURL(/\/login$/);
  await page.goto('/contacts');
  await expect(page).toHaveURL(/\/login$/);
});

test('login screen looks as approved @visual', async ({ page }) => {
  await page.goto('/login');
  await expect(page.locator('#username')).toBeVisible();
  await expect(page).toHaveScreenshot('login.png');
});
