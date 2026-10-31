// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import { OWNER_STATE } from '../support/session';

// This spec changes data. It runs in the "lifecycle" project, after the read-only specs.
test.use({ storageState: OWNER_STATE });

const FIXTURE = path.resolve(__dirname, '../../Party/Fixtures/idoklad-contacts.csv');
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
    await expect(page.locator('nav[aria-label="Kroky importu"] [aria-current="step"]')).toContainText('Sloupce');
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
    await expect(page.locator('[aria-current="step"]')).toContainText('Souhrn');
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
});
