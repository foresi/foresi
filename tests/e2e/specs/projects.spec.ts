// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { PROJECTS_OWNER_STATE } from '../support/session';

// The signed-in owner of "Example Projects" in tests/e2e/seed.php: the project "Website" of
// "Example Client s.r.o." with the Development workflow, two sections and six tasks.
test.use({ storageState: PROJECTS_OWNER_STATE });

async function openWebsite(page: Page, view: '' | '/board' = ''): Promise<void> {
  await page.goto('/projects');
  await page.getByRole('link', { name: 'Website', exact: true }).click();
  if ('/board' === view) {
    await page.getByRole('link', { name: 'Nástěnka' }).click();
  }
}

// What changes from one run or day to the next: the colour of a person's initials follows a random
// id, and the due dates are relative to today.
function volatile(page: Page) {
  return [page.locator('main [aria-hidden="true"].place-items-center'), page.locator('main td').filter({ hasText: /\d{1,2}\. \d{1,2}\. \d{4}/ }), page.locator('[data-card-due]')];
}

function card(page: Page, title: string) {
  return page.locator('[data-board-card]').filter({ hasText: title });
}

function column(page: Page, name: string) {
  return page.locator('[data-board-column]').filter({ has: page.getByRole('heading', { name }) });
}

test.describe('screens', () => {
  test('the list, the board and my tasks look as approved @visual', async ({ page }) => {
    await openWebsite(page);
    await expect(page.locator('[data-section]').first()).toBeVisible();
    await expect(page.locator('main')).toHaveScreenshot('list-content.png', { mask: volatile(page) });
    await page.getByRole('link', { name: 'Nástěnka' }).click();
    await expect(page.locator('[data-board-card]').first()).toBeVisible();
    await expect(page.locator('main')).toHaveScreenshot('board-content.png', { mask: volatile(page) });
    await page.goto('/my-tasks');
    await expect(page.locator('[data-task-group]').first()).toBeVisible();
    await expect(page.locator('main')).toHaveScreenshot('my-tasks-content.png', { mask: volatile(page) });
  });
});

test.describe('projects', () => {
  test('the projects index lists the seeded project with its client', async ({ page }) => {
    await page.goto('/projects');
    const row = page.locator('tbody tr').filter({ hasText: 'Website' });
    await expect(row).toContainText('Example Client s.r.o.');
  });

  test('a project is created in a dialog and opens empty', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Nový projekt' }).first().click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Název').fill('Intranet');
    await dialog.getByRole('button', { name: 'Založit projekt' }).click();
    await expect(page).toHaveURL(/\/projects\/[0-9a-f-]+$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Intranet');
    await expect(page.locator('main')).toContainText('Zatím žádné úkoly.');
  });

  test('quick add keeps the focus for the next task', async ({ page }) => {
    await openWebsite(page);
    const input = page.locator('#add-' + (await page.locator('[data-section]').filter({ hasText: 'Backlog' }).getAttribute('data-section')));
    await input.fill('Prepare the sitemap');
    await input.press('Enter');
    await expect(page.locator('main')).toContainText('Prepare the sitemap');
    await expect(input).toBeFocused();
    await input.fill('Check the redirects');
    await input.press('Enter');
    await expect(page.locator('main')).toContainText('Check the redirects');
    await expect(input).toBeFocused();
  });

  test('a task changes its status and priority and the activity says so', async ({ page }) => {
    await openWebsite(page);
    await page.getByRole('link', { name: 'Fix the contact form', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Fix the contact form');
    await page.locator('#task-status').selectOption({ label: 'Kontrola' });
    await expect(page.locator('#task-status')).toHaveValue(/.+/);
    await page.locator('#task-priority').selectOption({ label: 'Nízká' });
    const activity = page.locator('[data-task-history]');
    await expect(activity).toContainText('Kontrola');
    await expect(activity).toContainText('Nízká');
    await page.reload();
    await expect(page.locator('#task-status option:checked')).toHaveText('Kontrola');
    await expect(page.locator('#task-priority option:checked')).toHaveText('Nízká');
  });

  test('a card moves to another column through its menu', async ({ page }) => {
    await openWebsite(page, '/board');
    await expect(page.locator('[data-board-column]')).toHaveCount(7);
    const title = 'Write the copy for the about page';
    await card(page, title).getByRole('button', { name: /^Další akce pro / }).click();
    await page.getByRole('menuitem', { name: 'Přesunout do: Testování' }).click();
    await expect(column(page, 'Testování').locator('[data-board-card]').filter({ hasText: title })).toBeVisible();
    await page.reload();
    await expect(column(page, 'Testování').locator('[data-board-card]').filter({ hasText: title })).toBeVisible();
  });

  test('a card dragged by its handle stays in the other column after a reload', async ({ page }) => {
    await openWebsite(page, '/board');
    const title = 'Review the cookie banner';
    const handle = card(page, title).locator('[data-board-handle]');
    const target = column(page, 'Probíhá').locator('ol');
    const from = (await handle.boundingBox())!;
    const to = (await target.boundingBox())!;
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2 + 10, from.y + from.height / 2 + 10, { steps: 5 });
    await page.mouse.move(to.x + to.width / 2, to.y + 30, { steps: 15 });
    await page.mouse.up();
    await expect(column(page, 'Probíhá').locator('[data-board-card]').filter({ hasText: title })).toBeVisible();
    await page.reload();
    await expect(column(page, 'Probíhá').locator('[data-board-card]').filter({ hasText: title })).toBeVisible();
    await expect(column(page, 'Kontrola').locator('[data-board-card]').filter({ hasText: title })).toHaveCount(0);
  });

  test('my tasks groups the unfinished tasks by when they are due', async ({ page }) => {
    await page.goto('/my-tasks');
    const overdue = page.locator('[data-task-group="overdue"]');
    await expect(overdue).toContainText('Design the homepage');
    const today = page.locator('[data-task-group="today"]');
    await expect(today).toContainText('Fix the contact form');
  });

  test('the settings add a section and a status', async ({ page }) => {
    await openWebsite(page);
    await page.locator('[data-project-settings]').click();
    await page.locator('#section-add').fill('Ideas');
    await page.locator('#section-add').press('Enter');
    await expect(page.locator('[data-settings-sections]').locator('input[value="Ideas"]')).toBeVisible();
    await page.locator('#status-add').fill('Waiting for client');
    await page.getByRole('button', { name: 'Přidat stav' }).click();
    await expect(page.locator('[data-settings-statuses]').locator('input[value="Waiting for client"]')).toBeVisible();
  });
});
