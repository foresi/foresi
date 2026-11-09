// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
import { expect, test, type Page } from '@playwright/test';
import { PROJECTS_GUEST_STATE, PROJECTS_OWNER_STATE } from '../support/session';

// The time of "Example Projects" in tests/e2e/seed.php, Monday to Sunday. The week before this one:
// the owner's 2 h on "Design the homepage" on Monday, 45 min on "Fix the contact form" on
// Wednesday, 1 h not billable on the homepage on Thursday and 30 min on "Website" without a task on
// Friday; Jana Example's 2 h 30 min on "Pick the colour palette" on Tuesday. This week, on Monday:
// the owner's 1 h 30 min on the homepage, estimated at 5 h, and Jana's 1 h on the about page.
// The screens come first, before the other tests log time today.
test.use({ storageState: PROJECTS_OWNER_STATE });

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

// The Monday of this week and of the one before, as the week page has them: the organisation's
// today, not the one of the machine running the tests.
async function mondays(page: Page): Promise<{ thisMonday: string; lastMonday: string; lastSunday: string }> {
  await page.goto('/time');
  const thisMonday = (await page.locator('[data-time-day]').first().getAttribute('data-time-day')) ?? '';

  return { thisMonday, lastMonday: shift(thisMonday, -7), lastSunday: shift(thisMonday, -1) };
}

// A day as YYYY-MM-DD, some days on or back.
function shift(date: string, days: number): string {
  return new Date(new Date(`${date}T12:00:00Z`).getTime() + days * 86_400_000).toISOString().slice(0, 10);
}

function day(page: Page, key: string) {
  return page.locator(`[data-time-day="${key}"]`);
}

// What changes from one run to the next: the colour of a person's initials follows a random id.
// The dates of the inputs of the period are masked; the pointer leaves the page.
function volatile(page: Page) {
  return [page.locator('main [aria-hidden="true"].place-items-center'), page.locator('#report-from, #report-until')];
}

// The days of the week and of the period are relative to today, so every date gets the same text.
async function freezeDates(page: Page): Promise<void> {
  await page.mouse.move(0, 0);
  await page.locator('main').evaluate((main) => {
    const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); null !== node; node = walker.nextNode()) {
      node.textContent = (node.textContent ?? '').replace(/\d{1,2}\.\s\d{1,2}\.\s\d{4}/g, '1. 1. 2026');
    }
  });
}

function reportRows(page: Page) {
  return page.locator('[data-time-report-entries] tbody tr');
}

test.describe('screens', () => {
  test('the week and the report look as approved @visual', async ({ page }) => {
    const { lastMonday, lastSunday } = await mondays(page);
    await page.goto(`/time?week=${lastMonday}`);
    await expect(page.locator('[data-time-week-total]')).toHaveText('4 h 15 min');
    await freezeDates(page);
    await expect(page.locator('main')).toHaveScreenshot('week-content.png', { mask: volatile(page) });

    await page.goto(`/time/report?period=custom&from=${lastMonday}&until=${lastSunday}`);
    await expect(reportRows(page)).toHaveCount(5);
    await freezeDates(page);
    await expect(page.locator('main')).toHaveScreenshot('report-content.png', { mask: volatile(page) });
  });
});

test.describe('the week', () => {
  test('opens from the sidebar on this week, and the week before adds up its days', async ({ page }) => {
    const { thisMonday, lastMonday } = await mondays(page);
    await page.goto('/projects');
    await page.locator('#sidebar').getByRole('link', { name: 'Čas', exact: true }).click();
    await expect(page).toHaveURL(/\/time$/);
    await expect(page.locator('main h1')).toHaveText('Čas');
    await expect(page.locator('[data-time-day]')).toHaveCount(7);
    await expect(day(page, thisMonday).locator('[data-time-entry]').filter({ hasText: 'Design the homepage' })).toContainText('Second round of the hero');
    await expect(page.locator('[data-time-today]')).toHaveCount(1);

    await page.getByRole('link', { name: 'Předchozí týden' }).click();
    await expect(page).toHaveURL(new RegExp(`week=${lastMonday}`));
    await expect(page.locator('[data-time-week-total]')).toHaveText('4 h 15 min');
    await expect(page.locator('[data-time-today]')).toHaveCount(0);
    const totals = page.locator('[data-time-day] [data-time-day-total]');
    await expect(totals).toHaveText(['2 h', '0 min', '45 min', '1 h', '30 min', '0 min', '0 min']);
    // Thursday's review is not billable; Friday's call is on the project, without a task.
    const thursday = day(page, (await page.locator('[data-time-day]').nth(3).getAttribute('data-time-day')) ?? '');
    await expect(thursday.locator('[data-time-entry]')).toContainText('Internal review of the sketches');
    await expect(thursday.locator('[data-time-billable]')).toHaveCount(0);
    const friday = page.locator('[data-time-day]').nth(4).locator('[data-time-entry]');
    await expect(friday.locator('[data-time-link]')).toHaveText('Website');
    await expect(friday.locator('[data-time-link]')).toHaveAttribute('href', /\/projects\/[0-9a-f-]+$/);
    await expect(friday.locator('[data-time-billable]')).toBeVisible();

    // An owner reads the week of anyone of the team.
    await page.locator('#filter-person').selectOption({ label: 'Jana Example' });
    await page.locator('[data-time-person-filter]').getByRole('button', { name: 'Filtrovat' }).click();
    await expect(page).toHaveURL(new RegExp(`week=${lastMonday}`));
    await expect(page.locator('[data-time-week-total]')).toHaveText('2 h 30 min');
    await expect(page.locator('[data-time-entry]')).toHaveCount(1);
    await expect(page.locator('[data-time-entry]')).toContainText('Pick the colour palette');
  });
});

test.describe('the task', () => {
  test('shows the time against the estimate', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Design the homepage', exact: true }).click();
    await expect(page.locator('[data-task-time-total]')).toHaveText('4 h 30 min z 5 h');
    await expect(page.locator('[data-task-time] [data-time-entry]')).toHaveCount(3);
    await expect(page.locator('#task-estimate')).toHaveValue('5 h');
  });

  test('logs time in the dialog, billable as the project is, and the week has it today', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Fix the contact form', exact: true }).click();
    await expect(page.locator('[data-task-time-total]')).toHaveText('45 min');
    await page.locator('[data-task-time]').getByRole('link', { name: 'Zapsat čas' }).click();
    const dialog = page.getByRole('dialog', { name: 'Zapsat čas' });
    await expect(dialog.getByLabel('Doba')).toBeFocused();
    await expect(dialog.getByLabel('Fakturovatelné')).toBeChecked();
    await dialog.getByLabel('Doba').fill('1:30');
    await dialog.getByLabel('Poznámka').fill('Fixed the validation of the phone number');
    await dialog.getByRole('button', { name: 'Zapsat čas' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.locator('main')).toContainText('Zapsáno 1 h 30 min.');
    await expect(page.locator('[data-task-time-total]')).toHaveText('2 h 15 min');
    const entry = page.locator('[data-task-time] [data-time-entry]').filter({ hasText: 'Fixed the validation of the phone number' });
    await expect(entry.locator('[data-time-duration]')).toHaveText('1 h 30 min');
    await expect(entry.locator('[data-time-billable]')).toBeVisible();

    await page.goto('/time');
    const today = page.locator('[data-time-day]').filter({ has: page.locator('[data-time-today]') });
    await expect(today.locator('[data-time-entry]').filter({ hasText: 'Fix the contact form' })).toContainText('1 h 30 min');
  });

  test('runs the timer from the header, shown over every page until it is stopped there', async ({ page }) => {
    const title = 'Write the copy for the about page';
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: title, exact: true }).click();
    await expect(page.locator('[data-running-timer]')).toHaveCount(0);
    await page.locator('main header').getByRole('button', { name: 'Spustit časovač' }).click();
    const bar = page.getByRole('region', { name: 'Běžící časovač' });
    await expect(bar).toBeVisible();
    await expect(bar.locator('[data-running-timer-link]')).toHaveText(title);
    await expect(page.locator('main header').getByRole('button', { name: 'Zastavit časovač' })).toBeVisible();
    await expect(page.locator('[data-task-time] [data-time-running]')).toBeVisible();

    await page.goto('/projects');
    await expect(bar).toBeVisible();
    await page.goto('/time');
    await expect(bar).toBeVisible();
    await expect(page.locator('[data-time-entry]').filter({ hasText: title }).locator('[data-time-running]')).toBeVisible();

    await bar.locator('[data-running-timer-link]').click();
    await expect(page.locator('main h1')).toHaveText(title);
    await bar.getByRole('button', { name: 'Zastavit' }).click();
    await expect(page.locator('main')).toContainText(new RegExp(`Časovač je zastavený, zapsáno \\d+ min na „${title}“\\.`));
    await expect(page.locator('[data-running-timer]')).toHaveCount(0);
    await expect(page.locator('[data-task-time] [data-time-running]')).toHaveCount(0);
    await expect(page.locator('main header').getByRole('button', { name: 'Spustit časovač' })).toBeVisible();
  });
});

test.describe('the report', () => {
  test('narrows the period to a person and downloads the same entries as CSV', async ({ page }) => {
    const { lastMonday, thisMonday } = await mondays(page);
    await page.goto('/time');
    await page.getByRole('navigation', { name: 'Pohledy na čas' }).getByRole('link', { name: 'Výkaz' }).click();
    await expect(page).toHaveURL(/\/time\/report$/);
    await expect(page.locator('main h1')).toHaveText('Výkaz');

    const sunday = shift(thisMonday, 6);
    await page.locator('#report-from').fill(lastMonday);
    await page.locator('#report-until').fill(sunday);
    await page.locator('#report-person').selectOption({ label: 'Jana Example' });
    await page.locator('[data-time-report-filter]').getByRole('button', { name: 'Filtrovat' }).click();
    await expect(page.locator('#report-period')).toHaveValue('custom');
    await expect(reportRows(page)).toHaveCount(2);
    await expect(reportRows(page).first()).toContainText('Write the copy for the about page');
    await expect(reportRows(page).last()).toContainText('Pick the colour palette');
    await expect(page.locator('[data-time-report]')).toContainText('3 h 30 min');
    await expect(page.locator('[data-time-report]')).not.toContainText('Design the homepage');

    // Totals by task instead of by project.
    await page.locator('#report-group').selectOption('task');
    await page.locator('[data-time-report-filter]').getByRole('button', { name: 'Filtrovat' }).click();
    await expect(page.locator('[data-time-report-totals] h3')).toHaveText('Součty po úkolech');
    await expect(page.locator('[data-time-report-totals] tbody tr')).toHaveCount(2);

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('main header').getByRole('button', { name: 'Exportovat CSV' }).click(),
    ]);
    expect(download.suggestedFilename()).toBe(`vykaz-casu_${lastMonday}_${sunday}.csv`);
    const csv = (await readFile((await download.path()) ?? '', 'utf8')).replace(/^﻿/, '').trim().split(/\r?\n/);
    expect(csv[0]).toBe('Datum;Osoba;Klient;Projekt;Úkol;Minuty;Hodiny;Fakturovatelné;Poznámka');
    expect(csv).toHaveLength(3);
    expect(csv.slice(1).sort()).toEqual([
      `${shift(lastMonday, 1)};"Jana Example";"Example Client s.r.o.";Website;"Pick the colour palette";150;2,50;ano;"Three palettes for the client"`,
      `${thisMonday};"Jana Example";"Example Client s.r.o.";Website;"Write the copy for the about page";60;1,00;ano;"First draft of the about page"`,
    ].sort());
  });
});

test.describe('the guest', () => {
  test.use({ storageState: PROJECTS_GUEST_STATE });

  test('sees no time on a shared task and has neither the week nor the report', async ({ page }) => {
    await page.goto('/shared');
    await page.locator('[data-shared-projects]').getByRole('link', { name: 'Website' }).click();
    await page.getByRole('link', { name: 'Design the homepage', exact: true }).click();
    await expect(page.locator('main h1')).toHaveText('Design the homepage');
    await expect(page.locator('[data-task-time]')).toHaveCount(0);
    await expect(page.locator('#task-estimate')).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText('Wireframes for the hero');
    await expect(page.getByRole('button', { name: 'Spustit časovač' })).toHaveCount(0);
    await expect(page.locator('#sidebar').getByRole('link', { name: 'Čas', exact: true })).toHaveCount(0);
    for (const address of ['/time', '/time/report']) {
      const response = await page.goto(address);
      expect(response?.status(), address).toBe(403);
    }
  });
});

test.describe('on a phone', () => {
  test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

  test('the week has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/time');
    await expect(page.locator('[data-time-entry]').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('the report has no serious accessibility violations @a11y', async ({ page }) => {
    const { lastMonday, lastSunday } = await mondays(page);
    await page.goto(`/time/report?period=custom&from=${lastMonday}&until=${lastSunday}`);
    await expect(reportRows(page).first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('the task with its time has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/projects');
    await page.getByRole('link', { name: 'Website', exact: true }).click();
    await page.getByRole('link', { name: 'Design the homepage', exact: true }).click();
    await expect(page.locator('[data-task-time] [data-time-entry]').first()).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});
