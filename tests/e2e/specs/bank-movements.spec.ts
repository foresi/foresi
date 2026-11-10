// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { expectTableInFrame } from '../support/layout';
import { signIn } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1440, height: 960 };
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';
// The tenant of this spec in tests/e2e/seed.php: 20260001 paired by a verified notice, the
// unverified payment of 2 420 Kč for 20260002 waiting, and one bank e-mail of each kind.
const MOVEMENTS_OWNER = 'movements@example.com';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

function row(page: Page, text: string) {
  return page.locator('main tbody tr').filter({ hasText: text });
}

test.describe.serial('the bank movements', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await signIn(page, MOVEMENTS_OWNER);
  });

  test('each tab lists its movements and fits the screen @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      for (const tab of ['incoming', 'outgoing', 'unmatched', 'messages']) {
        await page.goto(tab === 'incoming' ? '/bank' : `/bank?tab=${tab}`);
        await expect(page.getByRole('heading', { level: 1, name: 'Banka' })).toBeVisible();
        await expect(page.locator('main tbody tr').first()).toBeVisible();
        await expectNoSeriousViolations(page);
        await expectNoSidewaysScroll(page);
        await expectTableInFrame(page.locator('main table'));
        await page.screenshot({ path: `${SHOTS}/bank-${tab}-${name}.png`, fullPage: true });
      }
    }
    await page.setViewportSize(DESKTOP);
    await page.goto('/bank');
    await expect(page.locator('#sidebar [aria-current="page"]')).toHaveText(/Banka/);
    await expect(row(page, '20260001').locator('[data-links] a')).toHaveText('20260001');
    await expect(row(page, '2 420,00')).toContainText('Neověřeno, nejdřív zkontrolujte v bance');
    await expect(page.getByRole('navigation', { name: 'Pohledy na banku' }).getByRole('link', { name: /Nespárované/ })).toContainText('4');
  });

  test('the unverified pair dialog fills the phone and keeps its warning in view @a11y @layout', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.goto('/bank?tab=unmatched');
    await row(page, '2 420,00').getByRole('button', { name: /Další akce/ }).click();
    await row(page, '2 420,00').getByRole('menuitem', { name: 'Spárovat' }).click();
    const dialog = page.getByRole('dialog', { name: 'Spárovat platbu 2 420,00 Kč' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-pair-unverified]')).toBeInViewport();
    await expect(dialog.getByRole('checkbox', { name: 'Platbu jsem ověřil(a) v bance' })).not.toBeChecked();
    const box = await dialog.boundingBox();
    expect(box?.width).toBe(PHONE.width);
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/bank-pair-unverified-phone.png`, fullPage: true });
    await dialog.getByRole('button', { name: 'Zrušit' }).click();
  });

  test('an unverified payment is paired by keyboard only after the bank check, then undone from the flash', async ({ page }) => {
    await page.goto('/bank?tab=unmatched');
    const waiting = row(page, '2 420,00');
    // Keyboard only. The bank did not vouch for this payment: the dialog warns, and nothing is ticked or filled in.
    await waiting.getByRole('button', { name: /Další akce/ }).focus();
    await page.keyboard.press('Enter');
    await waiting.getByRole('menuitem', { name: 'Spárovat' }).focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Spárovat platbu 2 420,00 Kč' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-pair-unverified]')).toContainText('Tuto platbu Foresi neověřil u banky. Než ji spárujete, zkontrolujte ji ve svém internetovém bankovnictví.');
    const suggested = dialog.locator('[data-pair-suggestions] [data-pair-invoice]').first();
    await expect(suggested).toContainText('20260002');
    await expect(suggested).toContainText('Stejný variabilní symbol');
    await expect(dialog.locator('[data-pair-invoice] input[type="checkbox"]:checked')).toHaveCount(0);
    await expect(suggested.getByRole('textbox')).toHaveValue('');
    const checked = dialog.getByRole('checkbox', { name: 'Platbu jsem ověřil(a) v bance' });
    await expect(checked).not.toBeChecked();
    await expectNoSeriousViolations(page);
    await page.screenshot({ path: `${SHOTS}/bank-pair-unverified-desktop.png`, fullPage: true });

    // Ticking fills in nothing either: the person types what they found in the bank.
    await suggested.getByRole('checkbox').check();
    await expect(suggested.getByRole('textbox')).toHaveValue('');
    await suggested.getByRole('textbox').fill('2 420,00');
    await expect(dialog.locator('[data-pair-sum]')).toContainText('Rozděleno 2 420,00 Kč / 2 420,00 Kč');

    // Without the bank check the server refuses and keeps what was typed.
    await page.locator('#pair-submit').focus();
    await page.keyboard.press('Enter');
    await expect(dialog.locator('[data-pair-checked]')).toContainText('Potvrďte, že jste platbu ověřil(a) v bance.');
    await expect(dialog.locator('[data-pair-suggestions] [data-pair-invoice]').first().getByRole('textbox')).toHaveValue(/^2\s?420,00$/);
    await page.screenshot({ path: `${SHOTS}/bank-pair-unverified-refused-desktop.png`, fullPage: true });

    await dialog.getByRole('checkbox', { name: 'Platbu jsem ověřil(a) v bance' }).focus();
    await page.keyboard.press('Space');
    await page.locator('#pair-submit').focus();
    await page.keyboard.press('Enter');
    await expect(dialog).toBeHidden();
    const flash = page.locator('[data-undo]');
    await expect(flash).toContainText('Spárováno s 20260002.');
    await expectNoSeriousViolations(page);
    await page.screenshot({ path: `${SHOTS}/bank-paired-desktop.png`, fullPage: true });
    await page.goto('/invoices');
    await expect(page.locator('tbody tr').filter({ hasText: '20260002' })).toContainText('Uhrazeno');

    await page.goBack();
    await page.goto('/bank?tab=unmatched');
    await expect(page.locator('[data-undo]')).toHaveCount(0);
    await page.goto('/bank');
    await row(page, '2 420,00').getByRole('button', { name: /Další akce/ }).click();
    await row(page, '2 420,00').getByRole('menuitem', { name: 'Zrušit spárování' }).click();
    const confirm = page.getByRole('dialog', { name: 'Zrušit spárování platby 2 420,00 Kč?' });
    await expect(confirm).toContainText('Faktura 20260002 bude znovu neuhrazená a tato platba se už nespáruje automaticky.');
    await expect(confirm.getByRole('button', { name: 'Ponechat spárování' })).toBeFocused();
    await expectNoSeriousViolations(page);
    await page.screenshot({ path: `${SHOTS}/bank-unpair-desktop.png`, fullPage: true });
    await confirm.getByRole('button', { name: 'Ponechat spárování' }).click();
    await expect(confirm).toBeHidden();
  });

  test('the flash right after pairing undoes it by keyboard, and the invoice is unpaid again', async ({ page }) => {
    await page.goto('/bank?tab=unmatched');
    await row(page, '350,00').getByRole('button', { name: /Další akce/ }).click();
    await row(page, '350,00').getByRole('menuitem', { name: 'Spárovat' }).click();
    const dialog = page.getByRole('dialog', { name: 'Spárovat platbu 350,00 Kč' });
    await expect(dialog).toBeVisible();
    // A verified payment: no warning and no bank check; a ticked invoice fills in what it has left.
    await expect(dialog.locator('[data-pair-unverified], [data-pair-checked]')).toHaveCount(0);
    await page.screenshot({ path: `${SHOTS}/bank-pair-desktop.png`, fullPage: true });
    await dialog.locator('#pair-q').fill('20260003');
    // The invoice is already among the suggestions: wait for the search, or it redraws the dialog under the pairing.
    const searched = page.waitForResponse((response) => response.url().includes('/pair') && response.request().method() === 'POST');
    await page.keyboard.press('Enter');
    await searched;
    const found = dialog.locator('[data-pair-invoice]').filter({ hasText: '20260003' });
    await expect(found).toBeVisible();
    await found.getByRole('checkbox').check();
    await expect(found.getByRole('textbox')).toHaveValue(/^350,00$/);
    await found.getByRole('textbox').press('Enter');
    await expect(dialog).toBeHidden();

    await expect(page.locator('[data-undo]')).toContainText('Spárováno s 20260003.');
    await page.locator('#bank-undo').focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Spárování je zrušené. Faktura 20260003 je znovu neuhrazená.' })).toBeVisible();
    await expect(row(page, '350,00')).toContainText('Zrušené spárování, spárujte ručně');
    await page.goto('/invoices');
    await expect(page.locator('tbody tr').filter({ hasText: '20260003' })).toContainText('Vystaveno');
  });

  test('the pair dialog fills the phone @a11y @layout', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.goto('/bank?tab=unmatched');
    await row(page, '350,00').getByRole('button', { name: /Další akce/ }).click();
    await row(page, '350,00').getByRole('menuitem', { name: 'Spárovat' }).click();
    const dialog = page.getByRole('dialog', { name: 'Spárovat platbu 350,00 Kč' });
    await expect(dialog).toBeVisible();
    const box = await dialog.boundingBox();
    expect(box?.width).toBe(PHONE.width);
    await expectNoSeriousViolations(page);
    await expectNoSidewaysScroll(page);
    await page.screenshot({ path: `${SHOTS}/bank-pair-phone.png`, fullPage: true });
    await dialog.getByRole('button', { name: 'Zrušit' }).click();
  });

  test('the messages say what became of each e-mail and offer what can be done @a11y', async ({ page }) => {
    await page.goto('/bank?tab=messages');
    await expect(row(page, 'Gmail Forwarding Confirmation')).toContainText('Není to e-mail z banky');
    await expect(row(page, 'Zaseknuto')).toContainText('Čeká na zpracování déle než hodinu');
    await expect(page.locator('main')).not.toContainText('parsers:');

    // The text is shown as text: the script in the body never runs.
    await row(page, 'Gmail Forwarding Confirmation').getByRole('button', { name: /Další akce/ }).click();
    await row(page, 'Gmail Forwarding Confirmation').getByRole('menuitem', { name: 'Zobrazit' }).click();
    const view = page.getByRole('dialog', { name: 'E-mail z banky' });
    await expect(view.locator('[data-message-text]')).toContainText("<script>alert('example')</script>");
    await expectNoSeriousViolations(page);
    await page.screenshot({ path: `${SHOTS}/bank-message-desktop.png`, fullPage: true });
    await view.getByRole('button', { name: 'Zavřít' }).first().click();

    const mismatch = row(page, 'Účet v e-mailu není účet firmy.');
    await mismatch.getByRole('button', { name: /Další akce/ }).click();
    await expect(mismatch.getByRole('menuitem', { name: 'Přesto zaznamenat' })).toHaveCount(0);
    await mismatch.getByRole('menuitem', { name: 'Zahodit' }).click();
    const dismiss = page.getByRole('dialog', { name: 'Zahodit e-mail?' });
    await expect(dismiss).toContainText('Zůstane uložený tak, jak přišel.');
    await expectNoSeriousViolations(page);
    await page.screenshot({ path: `${SHOTS}/bank-dismiss-desktop.png`, fullPage: true });
    await dismiss.getByRole('button', { name: 'Zahodit' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'E-mail je zahozený. Zůstává uložený.' })).toBeVisible();

    const untrusted = row(page, 'Odesílatele se nepodařilo ověřit.');
    await untrusted.getByRole('button', { name: /Další akce/ }).click();
    await untrusted.getByRole('menuitem', { name: 'Přesto zaznamenat' }).click();
    const accept = page.getByRole('dialog', { name: 'Přesto platbu zaznamenat?' });
    await expect(accept).toContainText('E-mail neprošel kontrolou odesílatele a může být podvržený');
    await expectNoSeriousViolations(page);
    await page.screenshot({ path: `${SHOTS}/bank-accept-desktop.png`, fullPage: true });
    await accept.getByRole('button', { name: 'Zrušit' }).click();
  });
});
