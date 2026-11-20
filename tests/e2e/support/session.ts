// SPDX-License-Identifier: AGPL-3.0-only

import { expect, type BrowserContext, type Page } from '@playwright/test';

// The throwaway credentials of tests/e2e/seed.php.
export const PASSWORD = process.env.E2E_PASSWORD ?? 'throwaway-password-1';
export const OWNER = 'testing@example.com';
export const EMPTY_OWNER = 'empty@example.com';
export const INVOICING_OWNER = 'invoicing@example.com';
export const FIRST_RUN_OWNER = 'first-run@example.com';
export const SENDING_OWNER = 'sending@example.com';
export const DIALOGS_OWNER = 'dialogs@example.com';
export const BANK_OWNER = 'bank@example.com';
export const RECURRING_OWNER = 'recurring@example.com';
export const REMINDERS_OWNER = 'reminders@example.com';
export const FIRM_MAIL_OWNER = 'firm-mail@example.com';
export const PROJECTS_OWNER = 'projects@example.com';
export const PURCHASING_OWNER = 'purchasing@example.com';
// The team member and the guest of the client in the organisation of PROJECTS_OWNER.
export const PROJECTS_MEMBER = 'member@example.com';
export const PROJECTS_GUEST = 'guest@example.com';

// Written once by specs/auth.setup.ts. Signing in on every test would trip the
// per-address login throttle (25 attempts a minute), so most specs reuse a session.
export const OWNER_STATE = '.auth/owner.json';
export const EMPTY_OWNER_STATE = '.auth/empty-owner.json';
export const INVOICING_OWNER_STATE = '.auth/invoicing-owner.json';
export const FIRST_RUN_OWNER_STATE = '.auth/first-run-owner.json';
export const SENDING_OWNER_STATE = '.auth/sending-owner.json';
export const DIALOGS_OWNER_STATE = '.auth/dialogs-owner.json';
export const BANK_OWNER_STATE = '.auth/bank-owner.json';
export const RECURRING_OWNER_STATE = '.auth/recurring-owner.json';
export const REMINDERS_OWNER_STATE = '.auth/reminders-owner.json';
export const FIRM_MAIL_OWNER_STATE = '.auth/firm-mail-owner.json';
export const PROJECTS_OWNER_STATE = '.auth/projects-owner.json';
export const PROJECTS_GUEST_STATE = '.auth/projects-guest.json';
export const PURCHASING_OWNER_STATE = '.auth/purchasing-owner.json';

// Each world of tests/e2e/seed.php is its own database with one organisation; the server picks
// it by the cookie e2e_world (tests/e2e/world.php). Signing in sets the world of the user, so a
// saved session carries it too. Without the cookie the server uses "main".
const WORLD_OF: Record<string, string> = {
  [OWNER]: 'main',
  [EMPTY_OWNER]: 'empty',
  [INVOICING_OWNER]: 'invoicing',
  [FIRST_RUN_OWNER]: 'first-run',
  [SENDING_OWNER]: 'sending',
  [DIALOGS_OWNER]: 'dialogs',
  [BANK_OWNER]: 'bank',
  [RECURRING_OWNER]: 'recurring',
  [REMINDERS_OWNER]: 'reminders',
  [FIRM_MAIL_OWNER]: 'firm-mail',
  [PROJECTS_OWNER]: 'projects',
  [PROJECTS_MEMBER]: 'projects',
  [PROJECTS_GUEST]: 'projects',
  [PURCHASING_OWNER]: 'purchasing',
  'people@example.com': 'people',
  'api@example.com': 'api',
  'sending-quiet@example.com': 'sending-quiet',
  'reminders-manual@example.com': 'reminders-manual',
  'paying@example.com': 'paying',
  'movements@example.com': 'movements',
  'advances@example.com': 'advances',
  'corrections@example.com': 'corrections',
  'accountant@example.com': 'accountant',
  'idoklad@example.com': 'idoklad',
  'idoklad-screens@example.com': 'idoklad-screens',
};

const BASE_URL = process.env.E2E_BASE_URL ?? `http://127.0.0.1:${process.env.E2E_PORT ?? '8099'}`;

export function worldOf(email: string): string {
  const world = WORLD_OF[email];
  if (world === undefined) {
    throw new Error(`No e2e world for ${email}: add it to WORLD_OF in tests/e2e/support/session.ts.`);
  }
  return world;
}

function worldCookie(world: string) {
  return { name: 'e2e_world', value: world, url: BASE_URL, sameSite: 'Lax' as const };
}

// A storage state with no session, only the world: for a visitor who is not signed in.
export function worldState(world: string) {
  const { hostname } = new URL(BASE_URL);
  return { cookies: [{ name: 'e2e_world', value: world, domain: hostname, path: '/', expires: -1, httpOnly: false, secure: false, sameSite: 'Lax' as const }], origins: [] };
}

// The header an API client without cookies sends to reach a world.
export function worldHeader(world: string): Record<string, string> {
  return { 'X-E2E-World': world };
}

export async function enterWorld(context: BrowserContext, world: string): Promise<void> {
  await context.addCookies([worldCookie(world)]);
}

export async function signIn(page: Page, email: string = OWNER): Promise<void> {
  await enterWorld(page.context(), worldOf(email));
  await page.goto('/login');
  await page.locator('#username').fill(email);
  await page.locator('#password').fill(PASSWORD);
  await page.locator('form button[type=submit]').click();
  await expect(page).not.toHaveURL(/\/login/);
}
