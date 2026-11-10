// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { PROJECTS_OWNER_STATE } from '../support/session';

// The time of last month on "Website" in tests/e2e/seed.php, which the dialog opens with: the
// owner's 1 h 30 min on "Set up the domain" and 30 min on the project without a task, and Jana
// Example's 1 h on "Pick the colour palette", all billable at the project's 1 200 Kč an hour. The
// tenant bills it from its one firm, "Example Agency s.r.o.". The flow issues, trashes and
// restores one invoice of two of them; the phone check only opens the dialog, so the spec leaves
// the time as it found it but for the invoice, and no other spec reads last month.
test.use({ storageState: PROJECTS_OWNER_STATE });

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

// The days of the period and of the entries are relative to today, so every date gets the same
// text; the pointer leaves the page, so no row shows its hover.
async function freezeDates(page: Page, scope: Locator): Promise<void> {
  await page.mouse.move(0, 0);
  await scope.evaluate((root) => {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); null !== node; node = walker.nextNode()) {
      node.textContent = (node.textContent ?? '').replace(/\d{1,2}\.\s\d{1,2}\.\s\d{4}/g, '1. 1. 2026');
    }
  });
}

async function openWebsite(page: Page): Promise<void> {
  await page.goto('/projects');
  await page.getByRole('link', { name: 'Website', exact: true }).click();
  await expect(page.locator('main h1')).toHaveText('Website');
}

async function openDialog(page: Page): Promise<Locator> {
  await openWebsite(page);
  await page.locator('main header').getByRole('link', { name: 'Vyfakturovat čas' }).click();
  const dialog = page.getByRole('dialog', { name: 'Vyfakturovat čas' });
  await expect(dialog.locator('[data-time-invoice-entry]').first()).toBeVisible();

  return dialog;
}

function entry(dialog: Locator, note: string): Locator {
  return dialog.locator('[data-time-invoice-entry]').filter({ hasText: note });
}

// The entry of "Set up the domain" in the Čas block of its task, a done task the board still shows.
async function domainEntry(page: Page): Promise<Locator> {
  await openWebsite(page);
  await page.getByRole('link', { name: 'Nástěnka' }).click();
  await page.locator('[data-board-card]').getByRole('link', { name: 'Set up the domain', exact: true }).click();
  await expect(page.locator('main h1')).toHaveText('Set up the domain');

  return page.locator('[data-task-time] [data-time-entry]').filter({ hasText: 'DNS records and the certificate' });
}

async function expectLockedBy(page: Page, number: string): Promise<void> {
  const time = await domainEntry(page);
  await expect(time.locator('[data-time-invoice]')).toHaveText(`Faktura ${number}`);
  await expect(time.locator('[data-time-edit]')).toHaveCount(0);
  await expect(time.locator('[data-time-delete]')).toHaveCount(0);
}

async function expectFree(page: Page): Promise<void> {
  const time = await domainEntry(page);
  await expect(time.locator('[data-time-invoice]')).toHaveCount(0);
  await expect(time.locator('[data-time-edit]')).toBeVisible();
}

test.describe('invoicing time', () => {
  test('bills the ticked time on a new invoice, frees it in the trash and locks it again on restore', async ({ page }) => {
    const dialog = await openDialog(page);
    await expect(dialog.locator('[data-time-invoice-entry]')).toHaveCount(3);
    await expect(dialog.locator('#time-invoice-firm option:checked')).toHaveText('Example Agency s.r.o.');
    await expect(dialog.locator('#time-invoice-client option:checked')).toHaveText('Example Client s.r.o.');
    await expect(dialog.locator('[data-time-invoice-target="tick"]:checked')).toHaveCount(3);
    await expect(dialog.locator('[data-time-invoice-hours]')).toHaveText('3,00 hod');
    await expect(dialog.locator('[data-time-invoice-amount]')).toHaveText('3 600,00 Kč');
    await freezeDates(page, dialog);
    await page.screenshot({ path: `${SHOTS}/time-invoice-dialog.png` });

    // Jana's hour stays for another invoice; each entry its own line.
    await entry(dialog, 'Mood board for the palette').getByRole('checkbox').uncheck();
    await expect(dialog.locator('[data-time-invoice-hours]')).toHaveText('2,00 hod');
    await expect(dialog.locator('[data-time-invoice-amount]')).toHaveText('2 400,00 Kč');
    await dialog.locator('#time-invoice-grouping').selectOption({ label: 'Řádek za každý záznam' });
    await expect(dialog.locator('[data-time-invoice-amount]')).toHaveText('2 400,00 Kč');

    await dialog.getByRole('button', { name: 'Pokračovat' }).click();
    await expect(page).toHaveURL(/\/invoices\/new\?billedWork=/);
    await expect(page.locator('tbody[data-invoice-form-target="line"]')).toHaveCount(2);
    await expect(page.locator('#lines-0-unit')).toHaveValue('hod');
    await expect(page.locator('#lines-1-unit')).toHaveValue('hod');
    const quantities = [await page.locator('#lines-0-quantity').inputValue(), await page.locator('#lines-1-quantity').inputValue()].sort();
    expect(quantities).toEqual(['0,5', '1,5']);
    await expect(page.locator('#lines-0-price')).toHaveValue(/^1\s200,00$/);
    await freezeDates(page, page.locator('main'));
    await page.screenshot({ path: `${SHOTS}/time-invoice-form.png`, fullPage: true });

    await page.locator('#invoice-save').click();
    await expect(page).toHaveURL(/\/invoices$/);
    const flash = await page.locator('[role=status]').filter({ hasText: /je uložená/ }).first().textContent();
    const number = /Faktura (\S+) je uložená/.exec(flash ?? '')?.[1] ?? '';
    expect(number).not.toBe('');

    await expectLockedBy(page, number);
    await page.locator('[data-task-time] [data-time-invoice]').first().click();
    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]+/);

    // The invoice in the trash: its time is free and offered again.
    await page.goto('/invoices');
    const row = page.locator('tbody tr').filter({ hasText: number });
    await row.getByRole('button', { name: /Další akce/ }).click();
    await row.getByRole('menuitem', { name: 'Smazat' }).click();
    const trash = page.getByRole('dialog', { name: `Smazat fakturu ${number}` });
    await trash.locator('#trash-reason').fill('Vystaveno omylem');
    await trash.getByRole('button', { name: 'Přesunout do koše' }).click();
    await expect(page.getByText(`Faktura ${number} je v koši.`)).toBeVisible();
    await expectFree(page);
    const again = await openDialog(page);
    await expect(entry(again, 'DNS records and the certificate')).toBeVisible();
    await again.getByRole('button', { name: 'Zrušit' }).click();
    await expect(again).toBeHidden();

    // Restored: the same time is locked to it again.
    await page.goto('/invoices');
    await page.getByRole('link', { name: /^Koš/ }).click();
    const trashed = page.locator('tbody tr').filter({ hasText: number });
    await trashed.getByRole('button', { name: /Další akce/ }).click();
    await trashed.getByRole('menuitem', { name: 'Obnovit' }).click();
    await expect(page.getByText(/je zpět v seznamu\./)).toBeVisible();
    await expectLockedBy(page, number);
  });
});

test.describe('on a phone', () => {
  test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

  test('the dialog has no serious accessibility violations @a11y', async ({ page }) => {
    const dialog = await openDialog(page);
    await expect(dialog.locator('[data-time-invoice-totals]')).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});
