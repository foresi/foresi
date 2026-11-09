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

// What changes from one run to the next: the colour of a person's initials follows a random id. The
// due dates, relative to today, get a fixed text from freezeDates instead.
function volatile(page: Page) {
  return [page.locator('main [aria-hidden="true"].place-items-center')];
}

// Relative due dates change the width of their column from one day to the next, so every date
// gets the same text before a screenshot; the pointer leaves the page, so no row shows its hover.
async function freezeDates(page: Page): Promise<void> {
  await page.mouse.move(0, 0);
  await page.locator('main td, main [data-card-due]').evaluateAll((elements) => elements.forEach((element) => {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); null !== node; node = walker.nextNode()) {
      node.textContent = (node.textContent ?? '').replace(/\d{1,2}\.\s\d{1,2}\.\s\d{4}/g, '1. 1. 2026');
    }
  }));
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
    await freezeDates(page);
    await expect(page.locator('main')).toHaveScreenshot('list-content.png', { mask: volatile(page) });
    await page.getByRole('link', { name: 'Nástěnka' }).click();
    await expect(page.locator('[data-board-card]').first()).toBeVisible();
    await freezeDates(page);
    await expect(page.locator('main')).toHaveScreenshot('board-content.png', { mask: volatile(page) });
    await page.goto('/my-tasks');
    await expect(page.locator('[data-task-group]').first()).toBeVisible();
    await freezeDates(page);
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
    await expect(page.locator('[data-project-empty]')).toContainText('Zatím žádné úkoly');
    await expect(page.locator('[data-project-empty]').getByRole('link', { name: 'Nový úkol' })).toBeVisible();
  });

  test('two tasks are added through the dialog of the header', async ({ page }) => {
    await openWebsite(page);
    for (const title of ['Prepare the sitemap', 'Check the redirects']) {
      await page.getByRole('link', { name: 'Nový úkol' }).click();
      const dialog = page.getByRole('dialog', { name: 'Nový úkol' });
      await expect(dialog.getByLabel('Název')).toBeFocused();
      await dialog.getByLabel('Název').fill(title);
      await dialog.getByLabel('Sekce').selectOption({ label: 'Backlog' });
      await dialog.getByRole('button', { name: 'Přidat úkol' }).click();
      await expect(dialog).toBeHidden();
      await expect(page.locator('main')).toContainText(`Úkol „${title}“ je přidaný.`);
      await expect(page.locator('[data-section]').filter({ hasText: 'Backlog' }).locator('tbody tr').filter({ hasText: title })).toBeVisible();
    }
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

  test('a task deleted from the list comes back by its Undo and from the trash', async ({ page }) => {
    await openWebsite(page);
    const title = 'Remove the old banner';
    await page.getByRole('link', { name: 'Nový úkol' }).click();
    await page.getByRole('dialog').getByLabel('Název').fill(title);
    await page.getByRole('dialog').getByRole('button', { name: 'Přidat úkol' }).click();
    const row = page.locator('[data-section] tbody tr').filter({ hasText: title });
    await expect(row).toBeVisible();

    // No question first: the message undoes it.
    await row.getByRole('button', { name: `Další akce pro ${title}` }).click();
    await page.getByRole('menuitem', { name: 'Smazat úkol' }).click();
    const flash = page.locator('[data-task-trashed]');
    await expect(flash).toContainText(`Úkol „${title}“ je v koši.`);
    await expect(row).toHaveCount(0);
    await flash.getByRole('button', { name: 'Vrátit zpět' }).click();
    await expect(page.locator('main')).toContainText(`Úkol „${title}“ je zpět.`);
    await expect(row).toBeVisible();

    await row.getByRole('button', { name: `Další akce pro ${title}` }).click();
    await page.getByRole('menuitem', { name: 'Smazat úkol' }).click();
    await expect(page.locator('[data-task-trashed]')).toContainText(`Úkol „${title}“ je v koši.`);
    await expect(row).toHaveCount(0);
    await page.getByRole('link', { name: 'Koš (1)' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Koš');
    await expect(page.locator('[data-trash-purge]')).toHaveText('Úkoly v koši se po 30 dnech smažou natrvalo.');
    await page.locator('tbody tr').filter({ hasText: title }).getByRole('button', { name: 'Obnovit' }).click();
    await expect(page.locator('main')).toContainText(`Úkol „${title}“ je zpět.`);
    await expect(page.locator('main')).toContainText('Koš je prázdný');

    await openWebsite(page);
    await expect(row).toBeVisible();
    await expect(page.locator('[data-project-trash]')).toHaveCount(0);
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
