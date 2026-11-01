// SPDX-License-Identifier: AGPL-3.0-only

import { expect, type Page } from '@playwright/test';

// The throwaway credentials of tests/e2e/seed.php.
export const PASSWORD = process.env.E2E_PASSWORD ?? 'throwaway-password-1';
export const OWNER = 'testing@example.com';
export const EMPTY_OWNER = 'empty@example.com';
export const GROUP_OWNER = 'group@example.com';

// Written once by specs/auth.setup.ts. Signing in on every test would trip the
// per-address login throttle (25 attempts a minute), so most specs reuse a session.
export const OWNER_STATE = '.auth/owner.json';
export const EMPTY_OWNER_STATE = '.auth/empty-owner.json';
export const GROUP_OWNER_STATE = '.auth/group-owner.json';

export async function signIn(page: Page, email: string = OWNER): Promise<void> {
  await page.goto('/login');
  await page.locator('#username').fill(email);
  await page.locator('#password').fill(PASSWORD);
  await page.locator('form button[type=submit]').click();
  await expect(page).not.toHaveURL(/\/login/);
}
