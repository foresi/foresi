// SPDX-License-Identifier: AGPL-3.0-only

import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { signIn } from '../support/session';

const DESKTOP = { width: 1440, height: 960 };
// The tenant of this spec in tests/e2e/seed.php: in last month invoices {year}0001 of 1 210 Kč
// at 21 % and {year}0002 of 560 Kč at 12 % and an advance of 1 210 Kč; in this month an
// invoice of 2 420 Kč. Nothing is exported yet.
const OWNER = 'accountant@example.com';
const CUSTOMER = 'Example Ledger Buyer a.s.';

// The firms' month, in Prague as the server keeps it: this one and the one before.
function months(): { thisMonth: string; lastMonth: string } {
  const [year, month] = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Prague' }).format(new Date()).split('-').map(Number);
  const last = month === 1 ? [year - 1, 12] : [year, month - 1];
  const format = (y: number, m: number): string => `${y}-${String(m).padStart(2, '0')}`;

  return { thisMonth: format(year, month), lastMonth: format(last[0], last[1]) };
}

const { thisMonth, lastMonth } = months();
const YEAR = lastMonth.slice(0, 4);
const FIRST = `${YEAR}0001`;
const SECOND = `${YEAR}0002`;

function row(page: Page, number: string) {
  return page.locator('tbody tr').filter({ has: page.getByRole('button', { name: `Další akce pro ${number}`, exact: true }) });
}

// The names in the central directory of a ZIP; the pack is small, so no ZIP64.
function zipEntries(path: string): string[] {
  const zip = readFileSync(path);
  let end = zip.length - 22;
  while (end >= 0 && zip.readUInt32LE(end) !== 0x06054b50) {
    end -= 1;
  }
  expect(end).toBeGreaterThanOrEqual(0);
  const names: string[] = [];
  let offset = zip.readUInt32LE(end + 16);
  for (let entry = 0; entry < zip.readUInt16LE(end + 10); entry += 1) {
    expect(zip.readUInt32LE(offset)).toBe(0x02014b50);
    const nameLength = zip.readUInt16LE(offset + 28);
    names.push(zip.toString('utf8', offset + 46, offset + 46 + nameLength));
    offset += 46 + nameLength + zip.readUInt16LE(offset + 30) + zip.readUInt16LE(offset + 32);
  }

  return names;
}

test.describe.serial('the accountant pack', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await signIn(page, OWNER);
  });

  test('the month filter narrows the list and sums it per VAT rate', async ({ page }) => {
    await page.goto('/invoices');
    await expect(page.locator('tbody tr')).toHaveCount(3);
    await page.locator('#invoice-month').fill(lastMonth);
    await page.locator('form:has(#invoice-month)').getByRole('button', { name: 'Hledat' }).click();

    await expect(page).toHaveURL(new RegExp(`month=${lastMonth}`));
    await expect(page.locator('tbody tr')).toHaveCount(2);
    const sums = page.locator('[data-list-sums]');
    await expect(sums.getByRole('heading', { name: 'Součty seznamu' })).toBeVisible();
    // 1 210 Kč and 560 Kč: 1 500 Kč of base, 270 Kč of VAT, a line per rate.
    await expect(sums).toContainText(/1\s500,00/);
    await expect(sums).toContainText(/1\s770,00/);
    await expect(sums.locator('[data-sum-line]')).toHaveCount(2);
    await expect(sums.locator('[data-sum-line]').filter({ hasText: '21 %' })).toContainText(/1\s210,00/);
    await expect(sums.locator('[data-sum-line]').filter({ hasText: '12 %' })).toContainText(/560,00/);
  });

  test('the overview shows what was invoiced and reminds of the month not yet passed on', async ({ page }) => {
    await page.goto('/');
    const section = page.locator('[data-dashboard-invoicing]');
    await expect(section.getByRole('heading', { name: 'Fakturace' })).toBeVisible();
    // This month's tile links to the list of the month.
    const invoiced = section.getByRole('link').filter({ hasText: /Vyfakturováno/ }).filter({ hasText: /2\s420,00/ });
    await expect(invoiced).toHaveAttribute('href', new RegExp(`month=${thisMonth}`));

    const pending = section.locator('[data-dashboard-pending]');
    await expect(pending).toContainText('ještě není předané účetní (3 doklady)');
    await pending.getByRole('link', { name: 'Předat účetní' }).click();
    await expect(page).toHaveURL(/\/invoices\/export\?/);
    await expect(page.locator('#export-month')).toHaveValue(lastMonth);
  });

  test('the pack of last month holds a folder per kind and marks its documents exported', async ({ page }) => {
    await page.goto('/invoices/export');
    await expect(page.locator('#export-month')).toHaveValue(lastMonth);
    const counts = page.locator('[data-export-counts]');
    await expect(counts.locator('[data-kind="invoice"]')).toHaveText('2');
    await expect(counts.locator('[data-kind="advance"]')).toHaveText('1');
    await expect(counts.locator('[data-kind="total"]')).toHaveText('3');

    const downloading = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Stáhnout ZIP' }).click();
    const download = await downloading;
    expect(download.suggestedFilename()).toBe(`example-ledger-s-r-o_${lastMonth}.zip`);
    const entries = zipEntries(await download.path());
    expect(entries).toContain('seznam.xlsx');
    // An ISDOC beside each invoice; the advance, which is no tax document, has its PDF only.
    for (const [folder, pdfs, isdocs] of [['faktury/', 2, 2], ['zalohove-faktury/', 1, 0]] as const) {
      const files = entries.filter((name) => name.startsWith(folder));
      expect(files.filter((name) => name.endsWith('.pdf'))).toHaveLength(pdfs);
      expect(files.filter((name) => name.endsWith('.isdoc'))).toHaveLength(isdocs);
    }
    expect(entries).toContain(`faktury/${lastMonth}-10_example-ledger-buyer-a-s_1210Kc_VS${FIRST}.pdf`);

    // Both invoices of the month carry the mark; the one of this month does not.
    await page.goto('/invoices');
    for (const number of [FIRST, SECOND]) {
      await expect(row(page, number).locator('[data-icon-cell="archive"]')).toHaveText('Exportováno');
    }
    await expect(page.locator('tbody [data-icon-cell="archive"]')).toHaveCount(2);
    await page.locator('#invoice-export').selectOption('exported');
    await page.locator('form:has(#invoice-export)').getByRole('button', { name: 'Hledat' }).click();
    await expect(page).toHaveURL(/export=exported/);
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await expect(row(page, FIRST)).toBeVisible();

    // Nothing of last month waits any more.
    await page.goto('/');
    await expect(page.locator('[data-dashboard-invoicing]')).toBeVisible();
    await expect(page.locator('[data-dashboard-pending]')).toHaveCount(0);
  });

  test('the row menu clears the export mark, so the next pack takes the document again', async ({ page }) => {
    await page.goto('/invoices?export=exported');
    await row(page, FIRST).getByRole('button', { name: `Další akce pro ${FIRST}`, exact: true }).click();
    await row(page, FIRST).getByRole('menuitem', { name: 'Zrušit příznak exportu' }).click();

    await expect(page.locator('main').getByRole('status')).toContainText(`Doklad ${FIRST} už nemá příznak exportu`);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(row(page, SECOND)).toBeVisible();
    await page.goto('/invoices?export=not_exported');
    await expect(row(page, FIRST)).toBeVisible();
    await expect(row(page, FIRST).locator('[data-icon-cell="archive"]')).toHaveCount(0);
    // A document never exported has nothing to clear.
    await row(page, FIRST).getByRole('button', { name: `Další akce pro ${FIRST}`, exact: true }).click();
    await expect(row(page, FIRST).getByRole('menuitem', { name: 'Historie' })).toBeVisible();
    await expect(row(page, FIRST).getByRole('menuitem', { name: 'Zrušit příznak exportu' })).toHaveCount(0);
  });

  test('the list downloads as CSV, named after its kind and month', async ({ page }) => {
    await page.goto(`/invoices?month=${lastMonth}`);
    const menu = page.locator('#list-export-menu');
    await page.getByRole('button', { name: 'Exportovat', exact: true }).click();
    await expect(menu.getByRole('menuitem')).toHaveText(['Exportovat jako CSV', 'Exportovat jako Excel (XLSX)']);

    const downloading = page.waitForEvent('download');
    await menu.getByRole('menuitem', { name: 'Exportovat jako CSV' }).click();
    const download = await downloading;
    expect(download.suggestedFilename()).toBe(`faktury_${lastMonth}.csv`);
    const csv = readFileSync(await download.path(), 'utf8');
    expect(csv).toContain(FIRST);
    expect(csv).toContain(SECOND);
    expect(csv).toContain(CUSTOMER);
  });
});
