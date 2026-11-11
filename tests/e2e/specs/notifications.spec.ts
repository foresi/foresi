// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { PROJECTS_GUEST_STATE, PROJECTS_OWNER_STATE } from '../support/session';

// The inbox of the owner of "Example Projects" in tests/e2e/seed.php: Jana Example commented on
// "Design the homepage", mentioned the owner on "Set up the domain" and gave them "Book the launch
// venue"; the guest heard that "Sign the lease" was shared with them. The tests run in order.
test.describe.configure({ mode: 'serial' });
test.use({ storageState: PROJECTS_OWNER_STATE });

function inbox(page: Page) {
  return page.locator('[data-notifications]');
}

function row(page: Page, title: string) {
  return inbox(page).locator('tbody tr').filter({ has: page.getByRole('link', { name: title, exact: true }) });
}

function badge(page: Page) {
  return page.locator('#sidebar a[href="/notifications"] [data-nav-count]');
}

async function unreadCount(page: Page): Promise<number> {
  return (await badge(page).count()) === 0 ? 0 : Number(await badge(page).textContent());
}

// The time of each row changes from one run to the next, so each gets the same text first.
async function freezeTimes(page: Page): Promise<void> {
  await page.locator('main td').evaluateAll((elements) => elements.forEach((element) => {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); null !== node; node = walker.nextNode()) {
      node.textContent = (node.textContent ?? '').replace(/\d{1,2}\.\s\d{1,2}\.\s\d{4}\s\d{1,2}:\d{2}/g, '1. 1. 2026 12:00');
    }
  }));
}

test.describe('screens', () => {
  test('the inbox looks as approved @visual', async ({ page }) => {
    // The homepage is opened by other specs before this one, or not: opened here, its row is read either way.
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Design the homepage', exact: true }).click();
    await expect(page.locator('main h1')).toHaveText('Design the homepage');
    await page.goto('/notifications');
    await expect(row(page, 'Set up the domain')).toBeVisible();
    await freezeTimes(page);
    await expect(page.locator('main')).toHaveScreenshot('inbox-content.png');
  });
});

test.describe('the inbox', () => {
  test('counts the unread rows in the sidebar and lists one row per task, newest first', async ({ page }) => {
    await page.goto('/');
    const link = page.locator('#sidebar [data-nav-group="main"]').getByRole('link', { name: /Oznámení/ });
    await expect(link).toBeVisible();
    expect(await unreadCount(page)).toBeGreaterThanOrEqual(2);
    await link.click();
    await expect(page).toHaveURL(/\/notifications$/);
    await expect(page.locator('main h1')).toHaveText('Oznámení');
    await expect(inbox(page).locator('tbody tr td:first-child [data-row-link]')).toHaveText(['Book the launch venue', 'Set up the domain', 'Design the homepage']);
    await expect(row(page, 'Set up the domain')).toContainText('Jana Example: Zmínka v komentáři');
    await expect(row(page, 'Set up the domain').locator('[data-unread]')).toHaveAttribute('data-unread', 'true');
    await expect(row(page, 'Book the launch venue')).toContainText('Jana Example: Úkol přiřazen vám');
    await expect(row(page, 'Book the launch venue')).toContainText('Launch');
  });

  test('narrows to mentions or to assignments', async ({ page }) => {
    await page.goto('/notifications');
    await page.locator('#filter-kind').selectOption('mentions');
    await page.locator('[data-notifications-filter]').getByRole('button', { name: 'Filtrovat' }).click();
    await expect(page).toHaveURL(/filter=mentions/);
    await expect(inbox(page).locator('tbody tr td:first-child [data-row-link]')).toHaveText(['Set up the domain']);
    await page.goto('/notifications?filter=assigned');
    await expect(inbox(page).locator('tbody tr td:first-child [data-row-link]')).toHaveText(['Book the launch venue']);
  });

  test('opening a task reads its row and the count drops', async ({ page }) => {
    await page.goto('/notifications');
    const before = await unreadCount(page);
    await row(page, 'Set up the domain').getByRole('link', { name: 'Set up the domain', exact: true }).click();
    await expect(page.locator('main h1')).toHaveText('Set up the domain');
    expect(await unreadCount(page)).toBe(before - 1);
    await page.goto('/notifications');
    await expect(row(page, 'Set up the domain').locator('[data-unread]')).toHaveAttribute('data-unread', 'false');
    // Still there: reading is not done.
    await expect(row(page, 'Set up the domain')).toBeVisible();
  });

  test('done moves the row to the done tab, and back again', async ({ page }) => {
    await page.goto('/notifications');
    await row(page, 'Set up the domain').getByRole('button', { name: /Další akce/ }).click();
    await row(page, 'Set up the domain').getByRole('menuitem', { name: 'Hotovo' }).click();
    await expect(row(page, 'Book the launch venue')).toBeVisible();
    await expect(row(page, 'Set up the domain')).toHaveCount(0);

    await page.getByRole('navigation', { name: 'Pohledy na oznámení' }).getByRole('link', { name: 'Hotové' }).click();
    await expect(page).toHaveURL(/tab=done/);
    await expect(page.locator('[data-notifications-kept]')).toHaveText('Hotová oznámení se uchovávají 3 měsíce, pak se smažou.');
    await expect(inbox(page).locator('tbody tr td:first-child [data-row-link]')).toHaveText(['Set up the domain']);
    await row(page, 'Set up the domain').getByRole('button', { name: /Další akce/ }).click();
    await row(page, 'Set up the domain').getByRole('menuitem', { name: 'Vrátit do nových' }).click();
    await expect(page.locator('[data-notifications-empty]')).toContainText('Zatím nic hotového');

    await page.goto('/notifications');
    await expect(row(page, 'Set up the domain')).toBeVisible();
  });

  test('marks everything read from the page header', async ({ page }) => {
    await page.goto('/notifications');
    expect(await unreadCount(page)).toBeGreaterThan(0);
    await page.locator('main header').getByRole('button', { name: 'Označit vše jako přečtené' }).click();
    await expect(inbox(page).locator('[data-unread="true"]')).toHaveCount(0);
    await expect(badge(page)).toHaveCount(0);
    await expect(page.locator('main header').getByRole('button', { name: 'Označit vše jako přečtené' })).toHaveCount(0);
  });
});

test.describe('the settings', () => {
  test('are reached from the inbox and keep a changed choice', async ({ page }) => {
    await page.goto('/notifications');
    await page.locator('main header').getByRole('link', { name: 'Nastavení' }).click();
    await expect(page).toHaveURL(/\/account\/notifications$/);
    await expect(page.locator('main h1')).toHaveText('Nastavení oznámení');
    await expect(page.getByLabel('Zmínky v komentářích')).toHaveValue('immediate');
    // At once only what is addressed to the person.
    await expect(page.getByLabel('Komentáře ve sledovaných úkolech').locator('option')).toHaveText(['V denním souhrnu', 'Nic']);

    await page.getByLabel('Komentáře ve sledovaných úkolech').selectOption('off');
    await page.getByLabel('Čas souhrnu').selectOption('17');
    await page.locator('[data-action-bar]').getByRole('button', { name: 'Uložit' }).click();
    await expect(page.getByText('Nastavení oznámení je uložené.')).toBeVisible();

    await page.reload();
    await expect(page.getByLabel('Komentáře ve sledovaných úkolech')).toHaveValue('off');
    await expect(page.getByLabel('Čas souhrnu')).toHaveValue('17');
    await expect(page.getByLabel('Zmínky v komentářích')).toHaveValue('immediate');
  });
});

test.describe('the guest', () => {
  test.use({ storageState: PROJECTS_GUEST_STATE });

  test('has the inbox of what is shared with them', async ({ page }) => {
    await page.goto('/notifications');
    await expect(page.locator('main h1')).toHaveText('Oznámení');
    await expect(row(page, 'Sign the lease')).toContainText('Projects Example: Úkol sdílen s vámi');
    await expect(page.locator('#sidebar [data-nav-group="main"]').getByRole('link')).toHaveText([/Sdíleno se mnou/, /Oznámení/]);
    await row(page, 'Sign the lease').getByRole('link', { name: 'Sign the lease', exact: true }).click();
    await expect(page.locator('main h1')).toHaveText('Sign the lease');
    await expect(badge(page)).toHaveCount(0);
  });
});
