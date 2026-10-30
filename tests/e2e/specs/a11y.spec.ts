// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { EMPTY_OWNER_STATE, OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

test.describe('signed out', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('login has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('#username')).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});

test.describe('signed in', () => {
  test.use({ storageState: OWNER_STATE });

  test('contact list has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/contacts');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('contact list with a selection has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('checkbox', { name: /^Select / }).nth(1).check();
    await expect(page.getByRole('region', { name: 'Bulk actions' })).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('contact detail has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('link', { name: 'Example Supplies s.r.o.', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('contact form has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/contacts/new');
    await expect(page.locator('#displayName')).toBeVisible();
    await expectNoSeriousViolations(page);
  });


  test('component catalogue has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/_catalogue?locale=en');
    await expect(page.getByRole('heading', { level: 1, name: 'Component catalogue' })).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});

test.describe('first run', () => {
  test.use({ storageState: EMPTY_OWNER_STATE });

  test('empty address book has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/contacts');
    await expect(page.getByText('Your address book is empty')).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});
