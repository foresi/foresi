// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { FIRST_RUN_OWNER_STATE } from '../support/session';

const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';

test.use({ storageState: FIRST_RUN_OWNER_STATE });

test('an empty organisation gets from nothing to the first invoice and its PDF', async ({ page }) => {
  await page.goto('/invoices/new');
  await expect(page.getByText('Nejdřív přidejte svou firmu')).toBeVisible();
  await expect(page.locator('#invoice-form')).toHaveCount(0);
  await page.screenshot({ path: `${SHOTS}/first-run-no-firm.png`, fullPage: true });

  await page.getByRole('link', { name: 'Přidat firmu' }).last().click();
  await expect(page).toHaveURL(/\/settings\/firms\/new$/);
  await page.locator('#lookup').fill('12345687');
  await page.getByRole('button', { name: /Example Company s\.r\.o\./ }).click();
  await expect(page.locator('#name')).toHaveValue('Example Company s.r.o.');
  await page.locator('#street').fill('Example Street 1');
  await page.locator('#city').fill('Example City');
  await page.locator('#postalCode').fill('100 00');
  await page.locator('#vatStatus').selectOption('registered');
  await page.locator('#registerEntry').fill('Example register, entry C 7');
  await page.locator('#bankAccounts').fill('CZ65 0800 0000 1920 0014 5399');
  await page.locator('button[type=submit]').last().click();
  await expect(page).toHaveURL(/\/settings\/firms$/);

  await page.getByRole('link', { name: 'Example Company s.r.o.' }).first().click();
  await expect(page).toHaveURL(/\/settings\/firms\/[0-9a-f-]+\/edit$/);
  const firm = /\/settings\/firms\/([0-9a-f-]+)\//.exec(page.url())?.[1];
  await page.goto(`/settings/firms/${firm}/invoicing/series/new`);
  await page.locator('#name').fill('Faktury');
  await page.locator('#format').fill('F{YYYY}{NNN}');
  await page.locator('#default').check();
  await page.locator('form button[type=submit]').last().click();
  await expect(page).toHaveURL(/\/invoicing$/);

  await page.goto('/invoices/new');
  await expect(page.getByText('Zatím žádní zákazníci. Použijte plus nebo adresář.')).toBeVisible();
  await page.screenshot({ path: `${SHOTS}/first-run-empty-customers.png`, fullPage: true });
  await page.locator('#customer-add').click();
  const dialog = page.getByRole('dialog', { name: 'Nový zákazník' });
  await dialog.locator('#displayName').fill('Example First Customer s.r.o.');
  await dialog.locator('#registrationNumber').fill('34567895');
  await dialog.getByText('Fakturační adresa').click();
  await dialog.locator('#street').fill('Example Street 2');
  await dialog.locator('#city').fill('Example City');
  await dialog.locator('#postalCode').fill('100 00');
  await dialog.getByRole('button', { name: 'Vytvořit zákazníka' }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#customer')).toHaveValue('Example First Customer s.r.o.');

  await page.locator('#lines-0-text').fill('První práce');
  await page.locator('#lines-0-price').fill('1000');
  await page.locator('#invoice-more').click();
  const [pdf] = await Promise.all([
    page.waitForResponse((response) => /\/invoices\/[0-9a-f-]+\/pdf$/.test(response.url())),
    page.getByRole('menuitem', { name: 'PDF a uložit' }).click(),
  ]);
  expect(pdf.status()).toBe(200);
  expect(pdf.headers()['content-type']).toContain('application/pdf');
  // The browser's own viewer holds the first answer, so the bytes are asked for again.
  const file = await page.request.get(pdf.url());
  expect((await file.body()).subarray(0, 5).toString()).toBe('%PDF-');

  await page.goto('/invoices');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody tr')).toContainText('F2026');
  await expect(page.locator('tbody tr')).toContainText('Vystaveno');
  await page.screenshot({ path: `${SHOTS}/first-run-list.png`, fullPage: true });
});
