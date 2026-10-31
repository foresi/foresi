// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import { expectFocusedClearOfActionBar } from '../support/layout';
import { EMPTY_OWNER_STATE, OWNER_STATE } from '../support/session';

// This spec changes data. It runs in the "lifecycle" project, after the read-only specs.
test.use({ storageState: OWNER_STATE });

const FIXTURE = path.resolve(__dirname, '../../Party/Fixtures/idoklad-contacts.csv');
const VIEWPORTS = { desktop: { width: 1440, height: 960 }, mobile: { width: 390, height: 844 } } as const;
// Content captures show the docked action bar where it sits at the end of the page, not where the
// viewport happened to end during the capture.
const STATIC_BAR = '[data-action-bar] { position: static !important; }';

async function undockActionBar(page: Page): Promise<void> {
  await page.addStyleTag({ content: STATIC_BAR });
  await page.locator('[data-action-bar]').evaluate((bar) => bar.removeAttribute('data-stuck'));
}
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function uploadFixture(page: Page): Promise<void> {
  await page.goto('/contacts');
  await page.getByRole('link', { name: 'Importovat' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Nový import' })).toBeVisible();
  await page.locator('#file').setInputFiles(FIXTURE);
  await page.getByRole('button', { name: 'Nahrát soubor' }).click();
  // The e2e environment reads the file inline, so the mapping comes at once.
  await expect(page.getByRole('heading', { level: 1, name: 'idoklad-contacts.csv' })).toBeVisible();
}

test.describe('import of contacts', () => {
  test.describe.configure({ mode: 'serial' });

  test('maps the columns, checks, runs, downloads the errors and undoes the import', async ({ page }) => {
    await uploadFixture(page);

    // Columns: the iDoklad preset proposed the fields.
    await expect(page.locator('nav[aria-label="Kroky importu"] [aria-current="step"]')).toContainText('Přiřazení sloupců');
    await expect(page.getByLabel('Firma', { exact: true })).toHaveValue('display_name');
    await expect(page.getByLabel('Sleva', { exact: true })).toHaveValue('');
    // Where the proposals come from is said once; confident columns carry no marker.
    await expect(page.locator('[data-mapping-basis]')).toContainText('Poznali jsme export z iDokladu a sloupce přiřadili podle něj.');
    await expect(page.locator('form[data-controller="import-mapping"]').getByText('Ověřte', { exact: true })).toHaveCount(0);

    // A required field left out is named above the columns; the check button stays enabled.
    await page.getByLabel('Firma', { exact: true }).selectOption('');
    await expect(page.locator('[data-missing-alert]')).toBeVisible();
    await expect(page.locator('[data-missing-alert]')).toContainText('Název');
    await expect(page.getByRole('button', { name: 'Zkontrolovat' })).toBeEnabled();
    await page.getByLabel('Firma', { exact: true }).selectOption('display_name');
    await expect(page.locator('[data-missing-alert]')).toBeHidden();

    // The blank cell option applies only to modes that update.
    await expect(page.locator('#blank_cells')).toBeHidden();
    await page.getByLabel(/Aktualizovat existující/).check();
    await expect(page.locator('#blank_cells')).toBeVisible();
    await page.getByRole('button', { name: 'Zkontrolovat' }).click();

    // Check.
    await expect(page.locator('[aria-current="step"]')).toContainText('Kontrola');
    await expect(page.locator('[data-kpi]').first()).toContainText('Vytvoří se');

    // Run.
    await page.getByRole('button', { name: 'Spustit import' }).click();
    await expect(page.locator('[aria-current="step"]')).toContainText('Výsledek');
    await expect(page.locator('[data-import-outcome]')).toContainText('Import je hotový.');
    // Opening the summary answers the notice.
    await expect(page.locator('[data-shell-notices]')).toHaveCount(0);

    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Stáhnout chyby' }).click();
    expect((await download).suggestedFilename()).toMatch(/^import-\d+-errors\.csv$/);

    await page.getByRole('link', { name: 'Importy' }).first().click();
    await expect(page.locator('tbody tr').first()).toContainText('idoklad-contacts.csv');
    await page.locator('tbody tr').first().getByRole('link').click();

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Vrátit import' }).click();
    await expect(page.locator('[data-import-outcome]')).toContainText('Import je vrácený.');
  });

  test('a check with problems marks each at its field and focuses the first, clear of the action bar', async ({ page }) => {
    await uploadFixture(page);
    const check = page.getByRole('button', { name: 'Zkontrolovat' });

    // No column for the name, and a match key no column fills.
    await page.getByLabel('Firma', { exact: true }).selectOption('');
    await page.getByLabel('IČ', { exact: true }).selectOption('');
    await page.locator('#match_key').selectOption('registration_number');
    await expect(check).toBeEnabled();
    await expect(page.locator('[data-action-bar-status]')).toHaveText('18 z 22 sloupců se importuje');
    await check.click();

    await expect(page).toHaveTitle(/^Chyba: idoklad-contacts\.csv/);
    await expect(page.locator('#column-0-error')).toHaveText('Přiřaďte k některému sloupci pole Název');
    await expect(page.locator('#column-0')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#match_key')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#match_key-error')).toBeVisible();
    // The choices are kept, and the bar still only counts.
    await expect(page.getByLabel('Firma', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('IČ', { exact: true })).toHaveValue('');
    await expect(page.locator('#match_key')).toHaveValue('registration_number');
    await expect(page.locator('[data-action-bar-status]')).toHaveText('18 z 22 sloupců se importuje');
    await expect(check).toBeEnabled();
    await expectFocusedClearOfActionBar(page, page.locator('#column-0'));

    // With the name back, the match key near the floating bar is the first problem.
    await page.getByLabel('Firma', { exact: true }).selectOption('display_name');
    await check.click();
    await expect(page.locator('#column-0')).not.toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#match_key')).toHaveAttribute('aria-invalid', 'true');
    await expectFocusedClearOfActionBar(page, page.locator('#match_key'));

    // On a phone too.
    await page.setViewportSize(VIEWPORTS.mobile);
    await check.click();
    await expect(page.locator('#match_key')).toHaveAttribute('aria-invalid', 'true');
    await expectFocusedClearOfActionBar(page, page.locator('#match_key'));

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Zrušit import' }).click();
    await expect(page.getByText('Import je zrušený.').first()).toBeVisible();
  });

  test('a guess by a similar name is marked in text and described to its select @a11y', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('link', { name: 'Importovat' }).click();
    await page.locator('#file').setInputFiles({ name: 'guess.csv', mimeType: 'text/csv', buffer: Buffer.from('Název,Emial\nExample Company,testing@example.com\n') });
    await page.getByRole('button', { name: 'Nahrát soubor' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'guess.csv' })).toBeVisible();

    await expect(page.locator('[data-mapping-basis]')).toContainText('Sloupce jsme přiřadili podle jejich názvů.');
    await expect(page.locator('#column-1-review')).toHaveText('Ověřte');
    await expect(page.locator('#column-1-review-note')).toHaveText('Pole jsme odhadli podle podobného názvu sloupce.');
    await expect(page.locator('#column-1')).toHaveAttribute('aria-describedby', 'column-1-review column-1-review-note');
    await expect(page.locator('#column-0-review')).toHaveCount(0);
    await expectNoSeriousViolations(page);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Zrušit import' }).click();
    await expect(page.getByText('Import je zrušený.').first()).toBeVisible();
  });

  test('mapping and check screens have no serious accessibility violations @a11y', async ({ page }) => {
    await uploadFixture(page);
    await expect(page.getByLabel('Firma', { exact: true })).toHaveValue('display_name');
    await expectNoSeriousViolations(page);

    await page.getByRole('button', { name: 'Zkontrolovat' }).click();
    await expect(page.locator('[aria-current="step"]')).toContainText('Kontrola');
    await expectNoSeriousViolations(page);

    // Leave nothing behind for the next run of the suite.
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Zrušit import' }).click();
    await expect(page.getByText('Import je zrušený.').first()).toBeVisible();
  });

  // Screenshots: the viewport (with the action bar docked to its bottom) and the main content alone.
  // This spec runs on the desktop project only, so the mobile captures set their own viewport. They
  // import into the empty tenant, which no other spec writes to, so the check shows the same counts
  // whether the whole suite runs or only the @visual specs.
  test.describe('screens', () => {
    test.use({ storageState: EMPTY_OWNER_STATE });

    for (const [name, viewport] of Object.entries(VIEWPORTS)) {
      test(`mapping and check screens look as approved on ${name} @visual`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await uploadFixture(page);
        await expect(page.getByLabel('Firma', { exact: true })).toHaveValue('display_name');
        await expect(page.locator('[data-action-bar-status]')).toHaveText('20 z 22 sloupců se importuje');
        await expect(page).toHaveScreenshot(`mapping-${name}.png`);
        await undockActionBar(page);
        await expect(page.locator('main')).toHaveScreenshot(`mapping-content-${name}.png`);

        await page.getByRole('button', { name: 'Zkontrolovat' }).click();
        await expect(page.locator('[aria-current="step"]')).toContainText('Kontrola');
        await expect(page.locator('[data-kpi]').first()).toBeVisible();
        await expect(page).toHaveScreenshot(`check-${name}.png`);
        await undockActionBar(page);
        await expect(page.locator('main')).toHaveScreenshot(`check-content-${name}.png`);

        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'Zrušit import' }).click();
        await expect(page.getByText('Import je zrušený.').first()).toBeVisible();
      });
    }
  });

  test('the docked action bar keeps focused fields in view and stays in the form @layout', async ({ page }) => {
    await uploadFixture(page);
    const bar = page.locator('form[data-controller="import-mapping"] [data-action-bar]');
    await expect(bar).toBeVisible();
    // Floating a little (12 px) above the bottom of the viewport while the long mapping scrolls under it, with a shadow.
    const viewportHeight = page.viewportSize()?.height ?? 0;
    const box = await bar.boundingBox();
    const bottom = Math.round((box?.y ?? 0) + (box?.height ?? 0));
    expect(bottom).toBeLessThanOrEqual(viewportHeight - 8);
    expect(bottom).toBeGreaterThanOrEqual(viewportHeight - 16);
    await expect(bar).toHaveAttribute('data-stuck', '');

    // A field reached with the keyboard is never hidden behind the bar (WCAG 2.4.11).
    const select = page.getByLabel('Firma', { exact: true });
    await select.focus();
    for (let step = 0; step < 30; step += 1) {
      await page.keyboard.press('Tab');
      const covered = await page.evaluate(() => {
        const focused = document.activeElement;
        const docked = document.querySelector('[data-action-bar]');
        if (!focused || !docked || docked.contains(focused)) {
          return false;
        }
        return focused.getBoundingClientRect().top >= docked.getBoundingClientRect().top;
      });
      expect(covered).toBe(false);
    }

    // At the end of the page it sits under the content, without a shadow.
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await expect(bar).not.toHaveAttribute('data-stuck', '');

    // On a low screen it is not docked at all.
    await page.setViewportSize({ width: 900, height: 420 });
    await expect(bar).toHaveCSS('position', 'static');

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Zrušit import' }).click();
    await expect(page.getByText('Import je zrušený.').first()).toBeVisible();
  });
});
