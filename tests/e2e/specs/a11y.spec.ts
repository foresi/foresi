// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { EMPTY_OWNER_STATE, INVOICING_OWNER_STATE, OWNER_STATE, PROJECTS_OWNER_STATE } from '../support/session';

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
    await page.getByRole('checkbox', { name: /^Vybrat řádek / }).nth(1).check();
    await expect(page.getByRole('region', { name: 'Hromadné akce' })).toBeVisible();
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
    await page.goto('/_catalogue?locale=cs');
    await expect(page.getByRole('heading', { level: 1, name: 'Katalog komponent' })).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

test.describe('e-mail settings', () => {
  test.use({ storageState: INVOICING_OWNER_STATE });

  async function open(page: Page, tab: string): Promise<void> {
    await page.goto('/settings/firms');
    await page.getByRole('link', { name: 'Example Payer s.r.o.', exact: true }).click();
    await page.getByRole('navigation', { name: 'Části firmy' }).getByRole('link', { name: 'E-maily' }).click();
    await page.waitForURL(/\/mail$/);
    if (tab === 'Poštovní server') {
      await page.getByRole('navigation', { name: 'Části nastavení e-mailů' }).getByRole('link', { name: tab }).click();
      await page.waitForURL(/\/mail\?tab=server$/);
    }
  }

  test('templates have no serious accessibility violations and no sideways scroll @a11y @layout', async ({ page }) => {
    await open(page, 'Šablony');
    await expect(page.locator('#body-cs')).toHaveValue(/\{Číslo dokladu\}/);
    // At load on a phone the floating action bar covers the first chips, which axe counts
    // against their target size; a keyboard or touch user reaches them scrolled clear of it.
    await page.getByRole('toolbar', { name: 'Proměnné Čeština' }).scrollIntoViewIfNeeded();
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
  });

  test('the mail server has no serious accessibility violations and no sideways scroll @a11y @layout', async ({ page }) => {
    await open(page, 'Poštovní server');
    await expect(page.locator('#host')).toHaveValue('smtp.example.com');
    await expect(page.locator('#password')).toHaveValue('');
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
  });
});

test.describe('first run', () => {
  test.use({ storageState: EMPTY_OWNER_STATE });

  test('empty address book has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/contacts');
    await expect(page.getByText('V adresáři zatím nic není')).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});

test.describe('projects and tasks', () => {
  test.use({ storageState: PROJECTS_OWNER_STATE });

  test('projects have no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/projects');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('project list has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await expect(page.locator('[data-section]').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('project board has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Nástěnka' }).click();
    await expect(page.locator('[data-board-card]').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('the dialog of a new task has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Nový úkol' }).click();
    await expect(page.getByRole('dialog', { name: 'Nový úkol' }).getByLabel('Název')).toBeFocused();
    await expectNoSeriousViolations(page);
  });

  test('task has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Design the homepage', exact: true }).click();
    await expect(page.locator('[data-task-details]')).toBeVisible();
    // With the seeded discussion (comments, mention, internal label), the files and the editor.
    await expect(page.locator('[data-comment]').first()).toBeVisible();
    await expect(page.locator('[data-attachment]').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('task with the comment preview open has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Design the homepage', exact: true }).click();
    const composer = page.locator('[data-comment-composer]');
    await composer.locator('#comment-body').fill('Some *text* to preview');
    await composer.getByRole('tab', { name: 'Náhled' }).click();
    await expect(composer.locator('[data-markdown-editor-preview] em')).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('project settings have no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.locator('[data-project-settings]').click();
    await expect(page.locator('[data-settings-statuses]')).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('my tasks have no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/my-tasks');
    await expect(page.locator('[data-task-group]').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('the inbox has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/notifications');
    await expect(page.locator('[data-notifications] tbody tr').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('the done notifications have no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/notifications?tab=done');
    await expect(page.locator('[data-notifications-kept]')).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('the notification settings have no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/account/notifications');
    await expect(page.locator('[data-notification-settings-events] select').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('labels have no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/settings/labels');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  // The e2e owner has no two-factor sign-in, so the page shows the notice and the empty list.
  test('API access has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/settings/api');
    await expect(page.getByRole('heading', { level: 1, name: 'Přístup k API' })).toBeVisible();
    await expect(page.locator('[data-api-token-two-factor]')).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});
