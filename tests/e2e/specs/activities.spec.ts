// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '../support/test';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/activities.php: the four seeded types, the client
// Example Roofs s.r.o., and the owner's calendar feed at /calendar/feed/e2e-activities-feed.ics.
const OWNER = 'activities@example.com';
const FEED = '/calendar/feed/e2e-activities-feed.ics';

// A tiny file standing in for the recording of a meeting.
const RECORDING = { name: 'recording.m4a', mimeType: 'audio/mp4', buffer: Buffer.from('not really audio') };

function day(offset: number): string {
  const date = new Date();
  date.setDate(date.getDate() + offset);

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

test.describe('activities', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
    await signIn(page, OWNER);
  });

  test('a call logged with its recording, a meeting done and the next step planned, on the timeline and in the feed', async ({ page }) => {
    await page.goto('/contacts');
    const href = await page.getByRole('link', { name: 'Example Roofs s.r.o.', exact: true }).getAttribute('href');
    expect(href).toMatch(/^\/contacts\/[0-9a-f-]{36}$/);
    await page.goto(`${href}?tab=activities`);
    const tab = page.locator('turbo-frame#contact-tab [data-record-activities]');
    await expect(tab).toContainText('Zatím žádné aktivity');

    // Log a call that happened, with its recording; the time fields follow the type.
    await tab.locator('[data-activity-log]').click();
    const log = page.getByRole('dialog', { name: 'Zapsat aktivitu' });
    await expect(log.locator('#activity-form')).toBeVisible();
    await expect(log.locator('[data-activity-link]')).toContainText('Example Roofs s.r.o.');
    await log.getByLabel('Typ').selectOption({ label: 'Hovor' });
    await log.getByLabel('Předmět').fill('First call');
    await log.getByLabel('Výsledek').fill('Wants a roof audit');
    await log.getByLabel('Soubor').setInputFiles(RECORDING);
    await log.getByRole('button', { name: 'Zapsat', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${href}\\?tab=activities$`));
    const done = page.locator('turbo-frame#contact-tab [data-activities-done]');
    await expect(done.locator('[data-activity-subject]')).toHaveText(['First call']);
    await expect(done.locator('[data-activity-files]')).toContainText('recording.m4a');

    // Plan a meeting at a time: a meeting has a start and no day field.
    await page.locator('turbo-frame#contact-tab [data-activity-plan]').click();
    const plan = page.getByRole('dialog', { name: 'Naplánovat aktivitu' });
    await expect(plan.getByLabel('Den')).toBeHidden();
    await plan.getByLabel('Předmět').fill('Kick-off meeting');
    await plan.getByLabel('Začátek').fill(`${day(3)}T10:00`);
    await plan.getByRole('button', { name: 'Naplánovat', exact: true }).click();
    const planned = page.locator('turbo-frame#contact-tab [data-activities-planned]');
    await expect(planned.locator('[data-activity-subject]')).toHaveText(['Kick-off meeting']);
    expect(await (await page.request.get(FEED)).text()).toContain('SUMMARY:Schůzka: Kick-off meeting');

    // Done and plan next: the meeting is done and a task is planned on a day, in one go.
    await planned.locator('[data-activity-complete]').click();
    const complete = page.getByRole('dialog', { name: 'Hotovo: Kick-off meeting' });
    await expect(complete.locator('#activity-complete-form')).toBeVisible();
    await complete.getByLabel('Výsledek').fill('Agreed on an audit');
    await complete.getByLabel('Další aktivita').selectOption({ label: 'Úkol' });
    await expect(complete.getByLabel('Začátek')).toBeHidden();
    await complete.getByLabel('Předmět další aktivity').fill('Send the audit offer');
    await complete.getByLabel('Den').fill(day(5));
    await complete.getByRole('button', { name: 'Označit jako hotové' }).click();

    await expect(planned.locator('[data-activity-subject]')).toHaveText(['Send the audit offer']);
    await expect(done.locator('[data-activity-subject]')).toHaveText(['Kick-off meeting', 'First call']);
    await expect(done.locator('[data-activity-outcome]').first()).toHaveText('Agreed on an audit');

    // The calendar app gets the task as an all-day entry, and the meeting no longer.
    const feed = await (await page.request.get(FEED)).text();
    expect(feed).toContain('SUMMARY:Úkol: Send the audit offer');
    expect(feed).toContain(`DTSTART;VALUE=DATE:${day(5).replaceAll('-', '')}`);
    expect(feed).not.toContain('Kick-off meeting');
  });
});
