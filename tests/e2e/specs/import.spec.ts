// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
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

    // A required field left out holds the check back and names the field.
    await page.getByLabel('Firma', { exact: true }).selectOption('');
    await expect(page.locator('[data-missing-alert]')).toBeVisible();
    await expect(page.locator('[data-missing-alert]')).toContainText('Název');
    await expect(page.getByRole('button', { name: 'Zkontrolovat' })).toBeDisabled();
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
    // Docked to the bottom of the viewport while the long mapping scrolls under it, with a shadow.
    const viewportHeight = page.viewportSize()?.height ?? 0;
    const box = await bar.boundingBox();
    expect(Math.round((box?.y ?? 0) + (box?.height ?? 0))).toBeGreaterThanOrEqual(viewportHeight);
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
