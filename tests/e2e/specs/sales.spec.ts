// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { openMoreActions } from '../support/header';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/sales.php: the stages the migration seeds, the NDA
// template "NDA for a deal" that reads {{ deal.* }} and {{ client.* }}, and the client
// Example Warehouse s.r.o.
const OWNER = 'sales@example.com';
const CLIENT = 'Example Warehouse s.r.o.';

test.describe('sales', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
    await signIn(page, OWNER);
  });

  test('a deal for a contact with an audit line and its NDA, won, followed by a monthly deal, the chain on the Deals tab', async ({ page }) => {
    await page.goto('/contacts');
    const href = await page.getByRole('link', { name: CLIENT, exact: true }).getAttribute('href');
    expect(href).toMatch(/^\/contacts\/[0-9a-f-]{36}$/);
    await page.goto(`${href}?tab=deals`);
    const tab = page.locator('turbo-frame#contact-tab [data-client-deals]');
    await expect(tab).toContainText('S tímto kontaktem zatím nemáte obchody');

    // A new deal for the contact, in its dialog, which follows to the deal.
    await tab.locator('[data-client-deal-new]').click();
    const create = page.getByRole('dialog', { name: 'Nový obchod' });
    await expect(create.locator('#deal-form')).toBeVisible();
    await expect(create.locator('[data-deal-client]')).toContainText(CLIENT);
    await create.getByLabel('Název').fill('Roof audit');
    await create.getByRole('button', { name: 'Vytvořit obchod' }).click();
    await expect(page).toHaveURL(/\/sales\/deals\/[0-9a-f-]{36}$/);
    const audit = page.url();
    await expect(page.locator('h1')).toHaveText('Roof audit');

    // An audit line typed in, once.
    await page.locator('[data-deal-line-new]').click();
    const line = page.getByRole('dialog', { name: 'Přidat položku' });
    await expect(line.locator('#deal-line-form')).toBeVisible();
    await line.getByLabel('Název').fill('Roof audit');
    await line.getByLabel('Jednotka').fill('kus');
    await line.getByLabel('Cena za jednotku').fill('12000');
    await line.getByRole('button', { name: 'Přidat položku', exact: true }).click();
    await expect(page.locator('[data-deal-lines]')).toContainText('Roof audit');
    await expect(page.locator('[data-deal-lines]')).toContainText('12 000,00 Kč');

    // New document from the deal: the NDA with the deal and the client linked, listed on the deal.
    await (await openMoreActions(page)).locator('[data-deal-action="new-document"]').click();
    const document = page.getByRole('dialog', { name: 'Nový dokument' });
    await expect(document.locator('#document-form')).toBeVisible();
    await expect(document.locator('[data-document-link]')).toHaveText([/Roof audit/, new RegExp(CLIENT)]);
    await document.getByLabel('Šablona').selectOption({ label: 'NDA for a deal' });
    await document.getByLabel('Název').fill('NDA for the roof audit');
    await document.getByRole('button', { name: 'Vytvořit' }).click();
    await expect(document).toBeHidden();
    // The documents are the deal's second tab, after the activities.
    await page.goto(`${audit}?tab=documents`);
    const documents = page.locator('turbo-frame#deal-section-documents [data-record-documents]');
    await expect(documents).toContainText('NDA for the roof audit');

    // The draft fills the template with the deal: its title, its line and its value.
    await documents.getByRole('link', { name: 'NDA for the roof audit' }).click();
    await expect(page).toHaveURL(/\/documents\/[0-9a-f-]{36}$/);
    const preview = page.frameLocator('iframe[data-document-preview="template"]');
    await expect(preview.locator('body')).toContainText(`Between Example Sales s.r.o. and ${CLIENT}, about Roof audit.`);
    await expect(preview.locator('body')).toContainText('Roof audit: 1 kus, 12 000,00 Kč, Jednorázově');
    await expect(preview.locator('body')).toContainText('Once 12 000,00 Kč.');
    await page.locator('header [data-document-generate] button').click();
    await expect(page.locator('[data-document-status]')).toHaveText('Připraveno');

    // Won, then a follow-up deal for the same client, which the dialog follows to.
    await page.goto(audit);
    await page.locator('[data-deal-win] button').click();
    await expect(page.locator('[data-deal-reopen]')).toBeVisible();
    await expect(page.locator('main header').first()).toContainText('Vyhraný');
    await (await openMoreActions(page)).locator('[data-deal-follow-up]').click();
    const followUp = page.getByRole('dialog', { name: 'Nový navazující obchod' });
    await expect(followUp.locator('#deal-follow-up-form')).toBeVisible();
    await followUp.getByLabel('Název').fill('Roof maintenance');
    const copy = followUp.getByRole('checkbox');
    if ((await copy.count()) > 0 && (await copy.first().isChecked())) {
      await copy.first().uncheck();
    }
    await followUp.getByRole('button', { name: 'Vytvořit obchod' }).click();
    await expect(page.locator('h1')).toHaveText('Roof maintenance');
    expect(page.url()).not.toBe(audit);

    // A monthly line on the follow-up deal: its value is monthly.
    await page.locator('[data-deal-line-new]').click();
    const monthly = page.getByRole('dialog', { name: 'Přidat položku' });
    await expect(monthly.locator('#deal-line-form')).toBeVisible();
    await monthly.getByLabel('Název').fill('Roof maintenance');
    await monthly.getByLabel('Jednotka').fill('měsíc');
    await monthly.getByLabel('Cena za jednotku').fill('1500');
    await monthly.getByLabel('Fakturace').selectOption({ label: 'Opakovaně' });
    await monthly.getByLabel('Každých').fill('1');
    await monthly.getByLabel('Období').selectOption({ label: 'Měsíce' });
    await monthly.getByRole('button', { name: 'Přidat položku', exact: true }).click();
    await expect(page.locator('[data-deal-lines]')).toContainText('Každý měsíc, bez konce');

    // The client's Deals tab shows both, the follow-up with the deal it follows from.
    await page.goto(`${href}?tab=deals`);
    const deals = page.locator('turbo-frame#contact-tab [data-client-deals]');
    await expect(deals.locator('[data-client-deal] > div > a')).toHaveText(['Roof maintenance', 'Roof audit']);
    const maintenance = deals.locator('[data-client-deal]').filter({ hasText: 'Roof maintenance' });
    await expect(maintenance.locator('[data-follows-from]')).toHaveText('Navazuje na Roof audit');
    await expect(maintenance).toContainText('1 500,00 Kč měsíčně');
    await expect(deals.locator('[data-client-deal]').nth(1)).toContainText('Vyhraný');
    await expect(deals.locator('[data-client-deal]').nth(1).locator('[data-follows-from]')).toHaveCount(0);
  });
});
