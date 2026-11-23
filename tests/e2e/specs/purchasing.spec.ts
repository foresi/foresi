// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { PROJECTS_GUEST_STATE, PURCHASING_OWNER_STATE } from '../support/session';
import { openMoreActions } from '../support/header';

// The signed-in owner of "Example Purchasing" in tests/e2e/seed.php: the VAT payer "Example Buyer
// s.r.o.", a supplier in the United States ("Example Cloud Inc.") and one in Czechia ("Example Office
// Supplies s.r.o."), and the ČNB rate of 14 October 2026 (23,50 Kč for a dollar) already stored, so no
// test waits for the bank. The tests run in order and share the data they make: the uploaded draft
// is completed and recorded by the third, trashed and restored by the fourth. Dates are fixed, so
// the evidence numbers (the two seeded documents hold R20260001 and R20260002, the two made here R20260003 and R20260004) and the amounts read the same on every run.
test.use({ storageState: PURCHASING_OWNER_STATE });
test.describe.configure({ mode: 'serial' });

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

// A one-page PDF written for the occasion, handed over like an upload.
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n');

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

// The supplier combobox: type a part of the name, take the option.
async function pickSupplier(page: Page, name: string): Promise<void> {
  await page.locator('#supplier').fill(name.slice(0, 12));
  // The combobox offers the names in a listbox.
  await page.getByRole('listbox').getByRole('option', { name }).click();
}

// A field of the form whose change sends it back for what the new value needs: the answer replaces the
// dialog's content, or the page, so the old field is marked and the new one is waited for.
async function choose(page: Page, id: string, label: string): Promise<void> {
  await page.locator(`#${id}`).evaluate((field) => field.setAttribute('data-stale', ''));
  await page.locator(`#${id}`).selectOption({ label });
  await expect(page.locator(`#${id}[data-stale]`)).toHaveCount(0);
  await expect(page.locator(`#${id} option:checked`)).toHaveText(label);
}

async function fillRecapRow(page: Page, index: number, rate: string, base: string, vat: string): Promise<void> {
  await page.locator(`#recap-${index}-rate`).fill(rate);
  await page.locator(`#recap-${index}-base`).fill(base);
  await page.locator(`#recap-${index}-vat`).fill(vat);
}

test.describe('received documents', () => {
  test('a PDF uploaded from the list becomes a draft to complete', async ({ page }) => {
    await page.goto('/purchasing');
    await expect(page.locator('main h1')).toHaveText('Náklady');
    await expect(page.getByRole('link', { name: /^K doplnění/ })).toBeVisible();
    await page.locator('main header').getByRole('link', { name: 'Nahrát doklady' }).click();
    const dialog = page.getByRole('dialog', { name: 'Nahrát přijaté doklady' });
    await dialog.locator('#upload-files').setInputFiles({ name: 'faktura-fv-7741.pdf', mimeType: 'application/pdf', buffer: PDF });
    await expect(dialog.locator('[data-attachment-upload-item][data-uploaded]')).toHaveCount(1);
    // The upload saved itself, so closing asks nothing and the list underneath is loaded again.
    await dialog.getByRole('button', { name: 'Hotovo' }).click();
    await expect(dialog).toBeHidden();

    const row = page.locator('tbody tr').filter({ hasText: 'Koncept z' });
    await expect(row).toHaveCount(1);
    await row.getByRole('link', { name: /Koncept z/ }).click();
    // The row opens the document in the side sheet at its own URL, full screen: a document with lines is a form.
    const sheet = page.locator('dialog#sheet');
    await expect(sheet.getByRole('heading', { level: 2 }).first()).toContainText('Koncept z');
    await expect(page).toHaveURL(/\/purchasing\/[0-9a-f-]{36}$/);
    // The original beside its values, nothing recorded and no number yet.
    await expect(sheet.locator('[data-purchasing-preview="pdf"]')).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'Zaevidovat' })).toBeVisible();
    // Back closes the sheet over the list.
    await page.goBack();
    await expect(sheet).toBeHidden();
    await expect(page).toHaveURL(/\/purchasing$/);
    // The same URL is the page of the document.
    await row.getByRole('link', { name: /Koncept z/ }).click();
    await sheet.getByRole('link', { name: 'Otevřít jako stránku' }).click();
    await expect(page.locator('main h1')).toContainText('Koncept z');
    await expect((await openMoreActions(page)).getByRole('menuitem', { name: 'Smazat koncept' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expectNoSeriousViolations(page);
  });

  test('a service from outside the EU in dollars is recorded with the rate typed by hand', async ({ page }) => {
    await page.goto('/purchasing');
    await page.locator('main header').getByRole('link', { name: 'Nový doklad' }).click();
    const dialog = page.getByRole('dialog', { name: 'Nový doklad' });
    await expect(dialog.locator('#kind')).toBeVisible();

    await choose(page, 'kind', 'Zahraniční faktura');
    await choose(page, 'treatment', 'Služba ze třetí země');
    await choose(page, 'currency', 'USD');
    await pickSupplier(page, 'Example Cloud Inc.');
    await page.locator('#supplierNumber').fill('INV-2026-1042');
    await page.locator('#issueDate').fill('2026-10-14');
    await page.locator('#receivedDate').fill('2026-10-15');
    await page.locator('#total').fill('100,00');
    // The rate the bank published for the day is offered; a rate typed over it wins and is kept as typed.
    await page.locator('#exchangeRate').fill('23,10');
    await fillRecapRow(page, 0, '21', '100,00', '0,00');
    await dialog.getByRole('button', { name: 'Zaevidovat' }).click();
    await expect(page.locator('main h1')).toHaveText('R20260003');
    await expect(page.locator('[role=status]').filter({ hasText: 'Doklad R20260003 je zaevidovaný.' })).toBeVisible();

    await expect(page.locator('#exchangeRate')).toHaveValue('23,1');
    await expect(page.getByText(/Kurz ČNB vyhlášený/)).toHaveCount(0);
    const figures = page.locator('[data-purchasing-figures]');
    await expect(figures).toContainText('2 310,00 Kč');
    await expect(figures).toContainText('485,10 Kč');
    // The supplier charges no VAT, so the total is the base; the VAT is declared by the firm.
    await expect(figures).toContainText('DPH k samovyměření v Kč');
    await expect(figures).not.toContainText('2 795,10 Kč');
    // Self-assessed in the month of the tax point, with no latest day.
    await expect(page.locator('[data-purchasing-deduction]')).toHaveText('Odpočet DPH od období 10/2026.');
    await expect(page.locator('#treatment option:checked')).toHaveText('Služba ze třetí země');
  });

  test('the draft is completed with two VAT rates and recorded', async ({ page }) => {
    await page.goto('/purchasing');
    await page.locator('tbody tr').filter({ hasText: 'Koncept z' }).getByRole('link', { name: /Koncept z/ }).click();
    // Completed and recorded in place, in the sheet over the list.
    const sheet = page.locator('dialog#sheet');
    await expect(sheet.getByRole('heading', { level: 2 }).first()).toContainText('Koncept z');

    await pickSupplier(page, 'Example Office Supplies s.r.o.');
    await page.locator('#supplierNumber').fill('FV-7741');
    await page.locator('#issueDate').fill('2026-10-20');
    await page.locator('#receivedDate').fill('2026-10-21');
    await page.locator('#total').fill('1 770,00');
    await fillRecapRow(page, 0, '21', '1000,00', '210,00');
    await page.locator('[data-recap-add]').click();
    await expect(page.locator('[data-recap-row]')).toHaveCount(2);
    await fillRecapRow(page, 1, '12', '500,00', '60,00');
    await sheet.getByRole('button', { name: 'Zaevidovat' }).click();
    await expect(sheet.getByRole('heading', { level: 2 }).first()).toHaveText('R20260004');
    await expect(sheet.locator('[role=status]').filter({ hasText: 'Doklad R20260004 je zaevidovaný.' })).toBeVisible();

    // The original stays beside the values; the CZK figures hold one row per rate.
    await expect(page.locator('[data-purchasing-preview="pdf"]')).toBeVisible();
    const figures = page.locator('[data-purchasing-figures]');
    await expect(figures).toContainText('1 500,00 Kč');
    await expect(figures).toContainText('270,00 Kč');
    await expect(figures).toContainText('1 770,00 Kč');
    const rows = page.locator('[data-purchasing-figure-rows] [role=row]');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(1)).toContainText('21 %');
    await expect(rows.nth(1)).toContainText('1 000,00 Kč');
    await expect(rows.nth(2)).toContainText('12 %');
    await expect(rows.nth(2)).toContainText('500,00 Kč');
    // Domestic VAT is deducted from the month of the later of the tax point and the received date, until the end of the second year after.
    await expect(page.locator('[data-purchasing-deduction]')).toHaveText(/^Odpočet DPH od období 10\/2026, nejpozději 31\.\s12\.\s2028\.$/);

    await page.goto('/purchasing');
    await expect(page.getByRole('link', { name: /^K doplnění 0/ })).toBeVisible();
    await page.getByRole('link', { name: /^Zaevidované/ }).click();
    // All, the view the list opens on, holds the same four rows: wait for the visit to land,
    // as Turbo marks the whole page busy (aria-busy on html) until it does.
    await expect(page.getByRole('link', { name: /^Zaevidované/ })).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('html')).not.toHaveAttribute('aria-busy');
    // The two recorded here and the two seeded ones.
    await expect(page.locator('tbody tr')).toHaveCount(4);
    await expectNoSeriousViolations(page);
  });

  test('a document in the trash keeps its number and comes back', async ({ page }) => {
    await page.goto('/purchasing?tab=recorded');
    await page.locator('tbody tr').filter({ hasText: 'FV-7741' }).getByRole('link', { name: 'R20260004' }).click();
    await page.locator('dialog#sheet').getByRole('link', { name: 'Otevřít jako stránku' }).click();
    await expect(page.locator('main h1')).toHaveText('R20260004');

    await (await openMoreActions(page)).getByRole('menuitem', { name: 'Smazat', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Smazat doklad R20260004' });
    await dialog.locator('#trash-reason').fill('Nahráno omylem');
    await dialog.getByRole('button', { name: 'Smazat doklad', exact: true }).click();
    await expect(page.locator('[role=status]').filter({ hasText: 'Doklad R20260004 je v koši.' })).toBeVisible();

    await page.goto('/purchasing?tab=trash');
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page.getByRole('link', { name: 'R20260004' }).click();
    await page.locator('dialog#sheet').getByRole('link', { name: 'Otevřít jako stránku' }).click();
    await expect(page.locator('[data-purchasing-trashed]')).toContainText('Nahráno omylem');
    // Nothing can be changed in the trash: the form is disabled, only the restore is offered.
    await expect(page.locator('#supplierNumber')).toBeDisabled();
    await expect(page.locator('main header').getByRole('button', { name: 'Uložit' })).toHaveCount(0);

    await page.locator('main header').getByRole('button', { name: 'Obnovit', exact: true }).click();
    await expect(page.locator('[role=status]').filter({ hasText: 'Doklad R20260004 je obnovený.' })).toBeVisible();
    await expect(page.locator('main h1')).toHaveText('R20260004');
    await expect(page.locator('[data-purchasing-trashed]')).toHaveCount(0);
    await expect(page.locator('#supplierNumber')).toHaveValue('FV-7741');

    await page.goto('/purchasing?tab=trash');
    await expect(page.locator('tbody tr')).toHaveCount(0);
  });
});

test.describe('margin', () => {
  test('a document is assigned to own use and the panel shows the recipient', async ({ page }) => {
    await page.goto('/purchasing?tab=recorded');
    await page.locator('tbody tr').filter({ hasText: 'FV-7741' }).getByRole('link', { name: 'R20260004' }).click();
    await page.locator('dialog#sheet').getByRole('link', { name: 'Otevřít jako stránku' }).click();
    const panel = page.locator('[data-purchasing-margin]');
    // Nobody bears the cost yet.
    await expect(panel.locator('[data-margin-share="unassigned"]').first()).toBeVisible();

    await panel.getByRole('link', { name: 'Přiřadit…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Přiřadit…' });
    await dialog.locator('#margin-type').selectOption({ label: 'Vlastní spotřeba' });
    await dialog.getByRole('button', { name: 'Uložit' }).click();

    await expect(page.locator('[role=status]').filter({ hasText: 'Doklad je přiřazený.' })).toBeVisible();
    await expect(panel.locator('[data-margin-whole]')).toContainText('Vlastní spotřeba');
    await expect(panel.locator('[data-margin-share="document"]').first()).toContainText('Vlastní spotřeba');
  });
});

test.describe('screens', () => {
  test('the list and the detail look as approved @visual', async ({ page }) => {
    // An instance holds one firm (ADR 0039), so the list holds the seeded documents too.
    await page.goto('/purchasing?tab=recorded');
    await expect(page.getByRole('link', { name: 'R20260001' })).toBeVisible();
    await page.mouse.move(0, 0);
    await expect(page.locator('main')).toHaveScreenshot('list-content.png');

    await page.getByRole('link', { name: 'R20260001' }).click();
    await page.locator('dialog#sheet').getByRole('link', { name: 'Otevřít jako stránku' }).click();
    await expect(page.locator('main h1')).toHaveText('R20260001');
    await page.mouse.move(0, 0);
    // The history says when each step happened.
    await expect(page.locator('main')).toHaveScreenshot('detail-content.png', { mask: [page.locator('[data-purchasing-history]')] });
  });
});

test.describe('guests', () => {
  test.use({ storageState: PROJECTS_GUEST_STATE });

  test('a guest has no received documents', async ({ page }) => {
    const response = await page.goto('/purchasing');
    expect(response?.status()).toBe(403);
    await page.goto('/projects');
    await expect(page.getByRole('link', { name: 'Náklady' })).toHaveCount(0);
  });
});
