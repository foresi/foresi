// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { signIn } from '../support/session';

// This spec changes data. It runs in the "lifecycle" project, after the read-only specs.
// The tenants of this spec in tests/e2e/seed.php hold a finished iDoklad pull and stored keys,
// so nothing here reaches iDoklad: three contacts, three series, invoice 20260008 that matches,
// 20260009 whose VAT is 421 Kč in iDoklad and 420 Kč by Foresi, a credit note (refused), an
// advance Z20260003 paid with its tax document D20260001, and one monthly template. The e2e
// environment handles messages inline, so a check or a run is done when its POST answers.
const OWNER = 'idoklad@example.com';
const SCREENS_OWNER = 'idoklad-screens@example.com';
const VIEWPORTS = { desktop: { width: 1440, height: 960 }, mobile: { width: 390, height: 844 } } as const;
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const STATIC_BAR = '[data-action-bar] { position: static !important; }';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function undockActionBar(page: Page): Promise<void> {
  await page.addStyleTag({ content: STATIC_BAR });
  await page.locator('[data-action-bar]').evaluate((bar) => bar.removeAttribute('data-stuck'));
}

// The firm's pull screen. With one firm the entry goes straight to it; after a run it would show
// the run, so the pull is opened by its own address.
async function openPull(page: Page): Promise<void> {
  await page.goto('/import/idoklad');
  await expect(page).toHaveURL(/\/import\/idoklad\/[0-9a-f-]{36}\//);
  const firm = new URL(page.url()).pathname.split('/')[3];
  await page.goto(`/import/idoklad/${firm}/pull`);
  await expect(page.getByRole('heading', { level: 1, name: 'Přesun z iDokladu' })).toBeVisible();
}

// The counts of one area in the table by area: created, matched, linked before, refused, differing.
async function expectStage(page: Page, stage: string, counts: number[]): Promise<void> {
  const row = page.getByRole('table', { name: 'Podle oblasti' }).locator('tbody tr').filter({ hasText: stage });
  await expect(row.locator('td')).toHaveText([stage, ...counts.map(String)]);
}

// What the import makes of the seeded pull, the same in the check and in the run.
async function expectReport(page: Page): Promise<void> {
  await expectStage(page, 'Kontakty', [3, 0, 0, 0, 0]);
  await expectStage(page, 'Číselné řady', [3, 0, 0, 0, 0]);
  // The two invoices, the advance and its tax document; the credit note is refused, 20260009 differs.
  await expectStage(page, 'Doklady', [3, 0, 0, 1, 1]);
  await expectStage(page, 'Platby', [1, 0, 0, 0, 0]);
  await expectStage(page, 'Pravidelné faktury', [1, 0, 0, 0, 0]);

  const refused = page.locator('[data-report-refused]');
  await expect(refused).toContainText('O20260001');
  await expect(refused).toContainText('Dobropisy se zatím neimportují.');
  const mismatch = page.locator('[data-report-mismatch]');
  await expect(mismatch).toContainText('20260009');
  await expect(mismatch).toContainText(/DPH: iDoklad 421,00\sKč, Foresi 420,00\sKč/);
  // By year: four documents of 7 261 Kč in iDoklad, three of 4 840 Kč come over, the rest explained.
  const year = page.getByRole('table', { name: 'Doklady podle roku' }).locator('tbody tr').filter({ hasText: '2026' });
  await expect(year.locator('td')).toHaveText(['2026', '4', /7\s261,00\sKč/, '3', /4\s840,00\sKč/, /2\s421,00\sKč/, '1', '0']);
  // The template comes paused; its next issues follow on from iDoklad's last one.
  await expect(page.locator('[data-report-templates]')).toContainText('1. 11. 2026 · 1. 12. 2026');
}

async function runCheck(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Zkontrolovat import' }).click();
  await expect(page).toHaveURL(/\/check$/);
  await expect(page.locator('[aria-current="step"]')).toContainText('Kontrola');
}

async function runImport(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Importovat', exact: true }).click();
  const dialog = page.getByRole('alertdialog', { name: 'Importovat z iDokladu?' });
  await expect(dialog).toContainText('Vytvoří se 11 záznamů, odmítnuto 1, liší se 1.');
  await dialog.getByRole('button', { name: 'Importovat', exact: true }).click();
  await expect(page).toHaveURL(/\/runs\/[0-9a-f-]{36}$/);
  await expect(page.locator('[data-run-outcome]')).toBeVisible();
}

async function undoImport(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Vrátit import' }).click();
  const dialog = page.getByRole('alertdialog', { name: 'Vrátit import?' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Vrátit', exact: true }).click();
  await expect(page.locator('main').getByText('Import je vrácený.')).toBeVisible();
  await expect(page.locator('[data-run-undone]')).toBeVisible();
}

test.describe('the move from iDoklad', () => {
  test.describe.configure({ mode: 'serial' });

  test('checks the stored pull, imports it, reconciles it and undoes it @a11y', async ({ page }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await signIn(page, OWNER);
    await openPull(page);

    // Pull: the stored keys and the finished pull, with its lists; no request to iDoklad.
    await expect(page.locator('[aria-current="step"]')).toContainText('Stažení');
    await expect(page.locator('[data-pull-state]')).toBeVisible();
    await expect(page.locator('main')).toContainText('example-client-id');
    await expect(page.locator('[data-quota]')).toContainText('42');
    await expectNoSeriousViolations(page);

    // Check: what the import would do, written nowhere yet.
    await runCheck(page);
    await expect(page.locator('main')).toContainText('Zatím se nic neukládá.');
    await expectReport(page);
    await expect(page.locator('[data-overdue]')).toBeVisible();
    await expectNoSeriousViolations(page);
    await page.goto('/invoices');
    await expect(page.locator('main')).not.toContainText('20260008');

    // Run, after the confirmation; the result reconciles what came over.
    await page.goBack();
    await runImport(page);
    await expect(page.locator('[aria-current="step"]')).toContainText('Výsledek');
    await expect(page.locator('[data-run-outcome]')).toHaveText('Importovalo se 11 záznamů.');
    await expectReport(page);
    await expect(page.locator('main')).toContainText('Možné do');
    await expectNoSeriousViolations(page);
    const run = page.url();

    await page.goto('/invoices');
    await expect(page.locator('main')).toContainText('20260008');
    await expect(page.locator('main')).not.toContainText('20260009');

    // Undo, after the confirmation: the documents go, the run says when.
    await page.goto(run);
    await undoImport(page);
    await expect(page.getByRole('button', { name: 'Vrátit import' })).toHaveCount(0);
    await expectNoSeriousViolations(page);
    await page.goto('/invoices');
    await expect(page.locator('main')).not.toContainText('20260008');

    // The history lists the pull, the check and the run.
    await page.goto(run);
    await page.getByRole('link', { name: 'Historie přesunu' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Historie přesunu' })).toBeVisible();
    await expect(page.locator('tbody tr')).toHaveCount(3);
    await expectNoSeriousViolations(page);
  });

  // Screenshots import into their own tenant, which nothing else touches, so they show the same
  // counts whichever specs ran before. One check and one run serve both viewports: a second run
  // would find the contacts and series of the first. Dates of the day are masked.
  test('pull, check and result look as approved @visual', async ({ page }) => {
    const capture = async (shot: string, masked: () => Locator[], content: boolean): Promise<void> => {
      for (const [name, viewport] of Object.entries(VIEWPORTS)) {
        await page.setViewportSize(viewport);
        await expect(page).toHaveScreenshot(`${shot}-${name}.png`, { mask: masked() });
        if (content) {
          await undockActionBar(page);
          await expect(page.locator('main')).toHaveScreenshot(`${shot}-content-${name}.png`, { mask: masked() });
          await page.reload();
        }
      }
      await page.setViewportSize(VIEWPORTS.desktop);
    };

    await page.setViewportSize(VIEWPORTS.desktop);
    await signIn(page, SCREENS_OWNER);
    await openPull(page);
    await capture('pull', () => [page.locator('main dl').last()], false);

    await runCheck(page);
    await capture('check', () => [page.locator('main p').filter({ hasText: 'Zatím se nic neukládá.' }), page.locator('#remind_from')], true);

    await runImport(page);
    await capture('result', () => [page.locator('main p').filter({ hasText: 'Možné do' })], true);
    await undoImport(page);
  });
});
