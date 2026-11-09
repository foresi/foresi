// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Browser, type Page } from '@playwright/test';
import { PASSWORD, PROJECTS_GUEST_STATE, PROJECTS_MEMBER, PROJECTS_OWNER_STATE } from '../support/session';

// The guest of the client in "Example Projects" of tests/e2e/seed.php: the section "Shared with
// client" of "Website" is shared with them, and so is, on its own, "Sign the lease" of "Office
// move", a project only chosen people see. The owner shares and closes; the tests run in order.
test.describe.configure({ mode: 'serial' });

async function as(browser: Browser, storageState: string): Promise<Page> {
  const context = await browser.newContext({ storageState });
  return context.newPage();
}

// The relative due dates and the colour of a person's initials change from one run to the next.
async function freezeDates(page: Page): Promise<void> {
  await page.locator('main td').evaluateAll((elements) => elements.forEach((element) => {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); null !== node; node = walker.nextNode()) {
      node.textContent = (node.textContent ?? '').replace(/\d{1,2}\.\s\d{1,2}\.\s\d{4}/g, '1. 1. 2026');
    }
  }));
}

test.describe('the guest', () => {
  test.use({ storageState: PROJECTS_GUEST_STATE });

  test('the start page of a guest looks as approved @visual', async ({ page }) => {
    await page.goto('/shared');
    await expect(page.locator('[data-shared-projects]')).toBeVisible();
    await freezeDates(page);
    await expect(page.locator('main')).toHaveScreenshot('shared-content.png', { mask: [page.locator('main [aria-hidden="true"].place-items-center')] });
  });

  test('lands on what is shared: the project of the shared section, then the task shared on its own', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL(/\/shared$/);
    await expect(page.locator('main h1')).toHaveText('Sdíleno se mnou');
    const project = page.locator('[data-shared-projects] tbody tr').filter({ hasText: 'Website' });
    await expect(project).toContainText('Shared with client');
    const group = page.locator('[data-shared-group]').filter({ hasText: 'Office move' });
    await expect(group.getByRole('link', { name: 'Sign the lease' })).toBeVisible();
    // The project of that task is a name for orientation, not a link.
    await expect(page.locator('main').getByRole('link', { name: 'Office move' })).toHaveCount(0);
    await expect(page.locator('#sidebar').getByRole('link', { name: 'Sdíleno se mnou' })).toHaveAttribute('aria-current', 'page');
  });

  test('sees the shared section of the project and nothing else of it, without the actions of the staff', async ({ page }) => {
    await page.goto('/shared');
    await page.locator('[data-shared-projects]').getByRole('link', { name: 'Website' }).click();
    await expect(page.locator('main h1')).toHaveText('Website');
    await expect(page.locator('[data-section]')).toHaveCount(1);
    await expect(page.getByRole('heading', { name: /Shared with client/ })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Design the homepage', exact: true })).toBeVisible();
    await expect(page.locator('main')).not.toContainText('Fix the contact form');
    await expect(page.locator('main')).not.toContainText('Backlog');
    await expect(page.locator('[data-project-settings], [data-project-share], [data-project-trash]')).toHaveCount(0);
    await expect(page.locator('main header nav').getByRole('link', { name: 'Sdíleno se mnou' })).toBeVisible();
  });

  test('sees a task shared on its own with the name of its project, and no other task of it', async ({ page }) => {
    await page.goto('/shared');
    await page.getByRole('link', { name: 'Sign the lease' }).click();
    await expect(page.locator('main h1')).toHaveText('Sign the lease');
    const crumbs = page.locator('main header nav');
    await expect(crumbs).toContainText('Office move');
    await expect(crumbs.getByRole('link', { name: 'Office move' })).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText('Order the moving boxes');
    await expect(page.locator('[data-task-share], [data-task-menu]')).toHaveCount(0);
  });

  test('does not find a task of another section or a project not shared with them', async ({ page, browser }) => {
    // Their addresses, as the owner has them.
    const owner = await as(browser, PROJECTS_OWNER_STATE);
    await owner.goto('/projects');
    const office = await owner.getByRole('link', { name: 'Office move', exact: true }).getAttribute('href');
    await owner.getByRole('link', { name: 'Website', exact: true }).click();
    const contactForm = await owner.getByRole('link', { name: 'Fix the contact form', exact: true }).getAttribute('href');
    await owner.context().close();

    for (const address of [office, contactForm]) {
      const response = await page.goto(address ?? '/');
      expect(response?.status(), address ?? '').toBe(404);
    }
    const response = await page.goto('/projects/new');
    expect(response?.status()).toBe(403);
    await page.goto('/projects');
    await expect(page).toHaveURL(/\/shared$/);
  });

  test('comments in the open and never sees an internal comment', async ({ page }) => {
    await page.goto('/shared');
    await page.locator('[data-shared-projects]').getByRole('link', { name: 'Website' }).click();
    await page.getByRole('link', { name: 'Design the homepage', exact: true }).click();
    const discussion = page.locator('[data-task-discussion]');
    await expect(discussion).toContainText('Looks good, the pitch reads well.');
    await expect(discussion).not.toContainText('First sketch is ready');
    await expect(page.locator('#comment-internal')).toHaveCount(0);
    await expect(page.locator('[data-comment-visibility]')).toHaveText('Komentář uvidí všichni, kdo vidí tento úkol.');
    await page.locator('[data-comment-composer] #comment-body').fill('Could the green be a little darker?');
    await page.locator('[data-comment-composer]').getByRole('button', { name: 'Přidat komentář' }).click();
    const mine = page.locator('[data-comment]').filter({ hasText: 'Could the green be a little darker?' });
    await expect(mine).toContainText('Guest Example');
    await expect(mine.locator('[data-comment-internal]')).toHaveCount(0);
  });
});

test.describe('the owner', () => {
  test.use({ storageState: PROJECTS_OWNER_STATE });

  test('shares a task with the guest from its header, and the guest finds it on their start page', async ({ page, browser }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Fix the contact form', exact: true }).click();
    await expect(page.locator('main h1')).toHaveText('Fix the contact form');
    await page.locator('main header').getByRole('link', { name: 'Sdílet' }).click();
    const dialog = page.getByRole('dialog', { name: /Sdílet úkol/ });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Přidat osobu').selectOption({ label: 'Guest Example' });
    await dialog.getByRole('button', { name: 'Přidat' }).click();
    await expect(dialog.locator('[data-sharing-messages]')).toContainText('Sdíleno s osobou Guest Example.');
    await expect(dialog.locator('[data-sharing-people]')).toContainText('Guest Example');
    await dialog.getByRole('button', { name: 'Hotovo' }).click();

    const guest = await as(browser, PROJECTS_GUEST_STATE);
    await guest.goto('/shared');
    // Its section is not shared with the guest: the task comes on its own, under the name of its project.
    const group = guest.locator('[data-shared-group]').filter({ hasText: 'Website' });
    await expect(group.getByRole('link', { name: 'Fix the contact form' })).toBeVisible();
    await guest.context().close();
  });

  test('makes a task private, and a team member it is not shared with no longer sees it', async ({ page, browser }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Review the cookie banner', exact: true }).click();
    await expect(page.locator('main h1')).toHaveText('Review the cookie banner');
    const address = page.url();
    await page.locator('main header').getByRole('link', { name: 'Sdílet' }).click();
    const dialog = page.getByRole('dialog', { name: /Sdílet úkol/ });
    await dialog.getByLabel('Soukromý úkol').check();
    await expect(dialog.locator('[data-sharing-messages]')).toBeVisible();
    await dialog.getByRole('button', { name: 'Hotovo' }).click();
    await expect(page.locator('[data-header-badge]')).toContainText('Soukromé');

    const context = await browser.newContext();
    const member = await context.newPage();
    await member.goto('/login');
    await member.locator('#username').fill(PROJECTS_MEMBER);
    await member.locator('#password').fill(PASSWORD);
    await member.locator('form button[type=submit]').click();
    await expect(member).not.toHaveURL(/\/login/);
    await member.goto('/projects');
    await member.getByRole('link', { name: 'Website', exact: true }).click();
    await expect(member.getByRole('link', { name: 'Design the homepage', exact: true })).toBeVisible();
    await expect(member.locator('main')).not.toContainText('Review the cookie banner');
    const response = await member.goto(address);
    expect(response?.status()).toBe(404);
    await context.close();
  });
});
