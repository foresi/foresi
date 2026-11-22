// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Page } from '@playwright/test';
import { signIn } from '../support/session';

// The world of this spec in tests/e2e/seed/documents.php: the NDA type and one template of it.
const OWNER = 'documents@example.com';

// A tiny file that starts as a PDF does, which is what the signed copy is checked for.
const SIGNED_COPY = { name: 'nda-signed.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n% signed copy\n%%EOF\n') };

async function addSigner(page: Page, name: string, email: string, side: string, order: string): Promise<void> {
  await page.locator('header [data-signer-add]').click();
  const dialog = page.getByRole('dialog', { name: 'Přidat podepisujícího' });
  await expect(dialog.locator('#signer-form')).toBeVisible();
  await dialog.getByLabel('Jméno').fill(name);
  await dialog.getByLabel('E-mail').fill(email);
  await dialog.getByLabel('Podepisuje za').selectOption({ label: side });
  await dialog.getByLabel('Pořadí').fill(order);
  await dialog.getByRole('button', { name: 'Přidat', exact: true }).click();
  await expect(page.locator(`[data-signer-order="${order}"]`)).toContainText(name);
}

test.describe('documents', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
    await signIn(page, OWNER);
  });

  test('a new NDA is generated, signed in two orders and completed', async ({ page }) => {
    await page.goto('/documents');
    await expect(page.locator('#sidebar a[href="/documents"]')).toBeVisible();

    // New document, in its dialog; the dialog follows to the new document.
    await page.locator('header [data-document-new]').click();
    const dialog = page.getByRole('dialog', { name: 'Nový dokument' });
    await expect(dialog.locator('#document-form')).toBeVisible();
    await expect(dialog.getByLabel('Šablona')).toHaveValue(/.+/);
    await dialog.getByLabel('Název').fill('NDA with Example Ltd');
    await dialog.getByRole('button', { name: 'Vytvořit' }).click();
    await expect(page).toHaveURL(/\/documents\/[0-9a-f-]{36}$/);
    await expect(page.locator('[data-document-status]')).toHaveText('Koncept');
    await expect(page.locator('iframe[data-document-preview="template"]')).toBeVisible();

    await page.locator('header [data-document-generate] button').click();
    await expect(page.locator('[data-document-status]')).toHaveText('Připraveno');
    await expect(page.locator('iframe[data-document-preview="pdf"]')).toBeVisible();

    // Two signers, one per order: the other party first, then our side.
    await addSigner(page, 'Jana Example', 'jana@example.com', 'Druhou stranu', '1');
    await addSigner(page, 'Petr Example', 'petr@example.com', 'Naši stranu', '2');

    // The signed copy, in its dialog; from a ready document it also means it went out.
    await page.locator('header [data-document-signed-copy]').first().click();
    const upload = page.getByRole('dialog', { name: 'Nahrát podepsanou kopii' });
    await expect(upload.locator('#signed-copy-form')).toBeVisible();
    await upload.getByLabel('Podepsané PDF').setInputFiles(SIGNED_COPY);
    await upload.getByRole('button', { name: 'Nahrát', exact: true }).click();
    await expect(page.locator('[data-document-status]')).toHaveText('K podpisu');
    await expect(page.locator('[data-document-signed-copy-link]')).toBeVisible();

    // Both signatures, in either order; the second completes the document.
    await page.locator('[data-signer-order="2"] [data-signer-row-signed] button').click();
    await expect(page.locator('[data-signer-order="2"] [data-signer-status]')).toHaveAttribute('data-signer-status', 'signed');
    await expect(page.locator('[data-document-status]')).toHaveText('K podpisu');
    await page.locator('[data-signer-order="1"] [data-signer-row-signed] button').click();
    await expect(page.locator('[data-document-status]')).toHaveText('Dokončeno');
    await expect(page.locator('[data-signer-status="signed"]')).toHaveCount(2);
    await expect(page.locator('header [data-document-again]')).toBeVisible();

    // The list shows it completed.
    await page.goto('/documents?status=completed');
    await expect(page.locator('[data-documents] tbody tr')).toHaveCount(1);
    await expect(page.locator('[data-documents]')).toContainText('NDA with Example Ltd');
  });
});
