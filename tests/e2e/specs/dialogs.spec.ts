// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { DIALOGS_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1440, height: 960 };
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

function row(page: Page, number: string): Locator {
  return page.locator('tbody tr').filter({ has: page.getByRole('button', { name: `Další akce pro ${number}`, exact: true }) });
}

async function fromMenu(page: Page, number: string, item: string): Promise<void> {
  await row(page, number).getByRole('button', { name: /Další akce/ }).click();
  await row(page, number).getByRole('menuitem', { name: item, exact: true }).click();
}

// The dialog is laid out and its first frame painted, so a screenshot shows it.
async function settled(dialog: Locator): Promise<void> {
  await expect(dialog).toBeVisible();
  await dialog.page().evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

// Inside the dialog, or in the browser's own controls (then the page has no focused element):
// never on the page behind.
async function focusIsInside(dialog: Locator): Promise<boolean> {
  return dialog.evaluate((element) => document.activeElement === null || document.activeElement === document.body || element.contains(document.activeElement));
}

test.use({ storageState: DIALOGS_OWNER_STATE });

test.describe.serial('dialogs', () => {
  test('send opens over the list with Send focused, a modal the keyboard cannot leave @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      await fromMenu(page, '20260001', 'Odeslat');
      const dialog = page.getByRole('dialog', { name: 'Odeslat fakturu 20260001' });
      await settled(dialog);

      await expect(page).toHaveURL(/\/invoices$/);
      await expect(dialog.getByRole('button', { name: 'Odeslat', exact: true })).toBeFocused();
      await expect(dialog.getByRole('checkbox', { name: 'buyer@example.com' })).toBeChecked();
      await expect(dialog.locator('[data-send-preview]')).toContainText('Faktura 20260001');
      await expect(dialog.locator('#subject')).toBeHidden();
      // Modal: the page behind is inert, Tab never leaves the dialog.
      expect(await dialog.evaluate((element) => element.matches(':modal'))).toBe(true);
      for (let presses = 0; presses < 8; presses++) {
        await page.keyboard.press('Tab');
        expect(await focusIsInside(dialog)).toBe(true);
      }
      const box = await dialog.boundingBox();
      if (name === 'phone') {
        // With fields it fills the phone.
        expect(box).toEqual({ x: 0, y: 0, width: PHONE.width, height: PHONE.height });
      } else {
        expect(box?.width).toBe(560);
      }
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/dialog-send-${name}.png` });

      // Nothing changed: Escape closes at once and the focus goes back to the row's menu.
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(row(page, '20260001').getByRole('button', { name: /Další akce/ })).toBeFocused();
    }
  });

  test('changed text asks inside the dialog before Escape, the X or a click outside closes it @a11y', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      await fromMenu(page, '20260002', 'Odeslat');
      const dialog = page.getByRole('dialog', { name: 'Odeslat fakturu 20260002' });
      await settled(dialog);
      await dialog.getByText('Upravit text').click();
      await expect(dialog.locator('[data-send-preview]')).toBeHidden();
      await dialog.locator('#subject').fill('Faktura 20260002 k úhradě');

      await page.keyboard.press('Escape');
      const keep = dialog.getByRole('button', { name: 'Pokračovat v úpravách' });
      await expect(keep).toBeFocused();
      await expect(dialog.getByText('Zahodit neuložené změny?')).toBeVisible();
      await expect(dialog.getByRole('button', { name: 'Odeslat', exact: true })).toBeHidden();
      await expectNoSeriousViolations(page);
      await settled(dialog);
      await page.screenshot({ path: `${SHOTS}/dialog-unsaved-${name}.png` });

      await keep.click();
      await expect(dialog.locator('#subject')).toHaveValue('Faktura 20260002 k úhradě');
      if (name === 'desktop') {
        // A dialog with fields ignores a click on the backdrop.
        await page.mouse.click(10, 10);
        await expect(dialog).toBeVisible();
      }
      await dialog.getByRole('button', { name: 'Zavřít' }).click();
      await dialog.getByRole('button', { name: 'Zahodit', exact: true }).click();
      await expect(dialog).toBeHidden();
    }
  });

  test('a refusal stays in the dialog with the summary and the focus on the field', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/invoices');
    await fromMenu(page, '20260002', 'Odeslat');
    const dialog = page.getByRole('dialog', { name: 'Odeslat fakturu 20260002' });
    await settled(dialog);
    await dialog.getByText('Upravit text').click();
    await dialog.locator('#others').fill('not-an-address');
    await dialog.getByRole('button', { name: 'Odeslat', exact: true }).click();

    await expect(dialog.locator('[data-error-summary]')).toContainText('not-an-address není platná e-mailová adresa.');
    await expect(dialog.locator('#others')).toBeFocused();
    await expect(dialog.locator('#others')).toHaveValue('not-an-address');
    await expect(page).toHaveURL(/\/invoices$/);
    await expectNoSeriousViolations(page);
    await settled(dialog);
    await page.screenshot({ path: `${SHOTS}/dialog-send-error-desktop.png` });
    for (const size of [PHONE]) {
      await page.setViewportSize(size);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/dialog-send-error-phone.png` });
    }
    await page.setViewportSize(DESKTOP);
    await dialog.getByRole('button', { name: 'Zrušit' }).click();
    await dialog.getByRole('button', { name: 'Zahodit', exact: true }).click();
    await expect(dialog).toBeHidden();
  });

  test('Enter sends once and the list shows it sent', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/invoices');
    await fromMenu(page, '20260001', 'Odeslat');
    const dialog = page.getByRole('dialog', { name: 'Odeslat fakturu 20260001' });
    await settled(dialog);

    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');

    await expect(page.getByText('Faktura 20260001 je zařazená k odeslání na buyer@example.com.')).toBeVisible();
    await expect(dialog).toBeHidden();
    // The e2e queue delivers at once: the stored send marks the row and the history holds exactly one.
    await expect(row(page, '20260001').locator('[data-icon-cell]')).toHaveText('Odesláno');
    await fromMenu(page, '20260001', 'Historie');
    await expect(page.locator('[data-send-entry]')).toHaveCount(1);
    await expect(page.locator('[data-send-entry]')).toContainText('buyer@example.com');
  });

  test('delete asks for the reason in a danger dialog and moves the invoice to the trash @a11y', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      await fromMenu(page, '20260003', 'Smazat');
      const dialog = page.getByRole('dialog', { name: 'Smazat fakturu 20260003' });
      await settled(dialog);
      await expect(dialog.getByRole('button', { name: 'Zrušit' })).toBeFocused();
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/dialog-trash-${name}.png` });
      await dialog.getByRole('button', { name: 'Přesunout do koše' }).click();
      await expect(dialog.locator('#trash-reason')).toBeFocused();
      await expect(dialog).toContainText('Napište, proč fakturu mažete.');
      await settled(dialog);
      await page.screenshot({ path: `${SHOTS}/dialog-trash-error-${name}.png` });
      if (name === 'desktop') {
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
      }
    }
    const dialog = page.getByRole('dialog', { name: 'Smazat fakturu 20260003' });
    await dialog.locator('#trash-reason').fill('Vystaveno omylem');
    await dialog.getByRole('button', { name: 'Přesunout do koše' }).click();

    await expect(page.getByText('Faktura 20260003 je v koši.')).toBeVisible();
    await page.setViewportSize(DESKTOP);
    await page.getByRole('link', { name: /^Koš/ }).click();
    await expect(row(page, '20260003')).toContainText('Vystaveno omylem');
  });

  test('marking delivered asks in the shared confirm dialog, which names the outcome @a11y', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      await fromMenu(page, '20260002', 'Označit jako doručené');
      const dialog = page.getByRole('alertdialog', { name: 'Označit fakturu 20260002 jako doručenou?' });
      await settled(dialog);
      await expect(dialog).toContainText('Doručenou fakturu už nepůjde smazat.');
      // The safe answer has the focus; a confirmation stays centred on a phone.
      await expect(dialog.getByRole('button', { name: 'Zrušit' })).toBeFocused();
      if (name === 'phone') {
        expect((await dialog.boundingBox())?.width).toBeLessThan(PHONE.width);
      }
      await expectNoSeriousViolations(page);
      await page.screenshot({ path: `${SHOTS}/dialog-confirm-${name}.png` });
      await dialog.getByRole('button', { name: 'Zrušit' }).click();
      await expect(dialog).toBeHidden();
      await expect(row(page, '20260002')).not.toContainText('Doručeno');
    }
    await page.setViewportSize(DESKTOP);
    await fromMenu(page, '20260002', 'Označit jako doručené');
    await page.getByRole('alertdialog').getByRole('button', { name: 'Označit jako doručenou' }).click();
    await expect(page.getByText('Faktura 20260002 je označená jako doručená.')).toBeVisible();
    await expect(row(page, '20260002')).toContainText('Doručeno');
  });

  test('a new customer is created in a dialog over the invoice form and picked with its defaults @a11y', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices/new');
      await page.locator('#lines-0-text').fill('Zůstane');
      await page.locator('#lines-0-price').fill('750');
      await page.locator('#customer-add').click();
      const dialog = page.getByRole('dialog', { name: 'Nový zákazník' });
      await settled(dialog);
      await expect(dialog.locator('#lookup')).toBeFocused();
      await expect(page).toHaveURL(/\/invoices\/new$/);
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/dialog-customer-${name}.png` });
      if (name === 'phone') {
        // Back closes the dialog and leaves the form as it was.
        await page.goBack();
        await expect(dialog).toBeHidden();
        await expect(page).toHaveURL(/\/invoices\/new$/);
        await expect(page.locator('#lines-0-text')).toHaveValue('Zůstane');
        // Forward onto the step the dialog left reopens nothing and keeps the form.
        await page.goForward();
        await expect(page.locator('dialog[open]')).toHaveCount(0);
        await expect(page.locator('#lines-0-text')).toHaveValue('Zůstane');
        continue;
      }
      await dialog.locator('#lookup').fill('12345687');
      await dialog.getByRole('button', { name: /Example Company s\.r\.o\./ }).click();
      await expect(dialog.locator('#displayName')).toHaveValue('Example Company s.r.o.');
      await dialog.locator('#email').fill('company@example.com');
      await dialog.getByRole('button', { name: 'Vytvořit zákazníka' }).click();

      await expect(dialog).toBeHidden();
      await expect(page.locator('#customer')).toHaveValue('Example Company s.r.o.');
      await expect(page.locator('#customer')).toBeFocused();
      await expect(page.locator('[data-customer-summary]')).toContainText('12345687');
      await expect(page.locator('#lines-0-text')).toHaveValue('Zůstane');
      await expect(page).toHaveURL(/\/invoices\/new$/);
      // The same company again: the dialog warns in place and the decision takes the focus.
      await page.locator('#customer-add').click();
      await dialog.locator('#lookup').fill('12345687');
      await dialog.getByRole('button', { name: /Example Company s\.r\.o\./ }).first().click();
      await dialog.getByRole('button', { name: 'Vytvořit zákazníka' }).click();
      const warning = dialog.locator('[data-duplicate-warning]');
      await expect(warning).toContainText('Example Company s.r.o.');
      await expect(warning.getByRole('button', { name: 'Uložit i tak' })).toBeFocused();
      await expectNoSeriousViolations(page);
      await settled(dialog);
      await page.screenshot({ path: `${SHOTS}/dialog-customer-duplicate-${name}.png` });
      await dialog.getByRole('button', { name: 'Zrušit' }).click();
      await dialog.getByRole('button', { name: 'Zahodit', exact: true }).click();
      await expect(dialog).toBeHidden();
      await expect(page.locator('#customer')).toHaveValue('Example Company s.r.o.');

      await page.getByRole('button', { name: 'Uložit' }).click();
      await expect(page).toHaveURL(/\/invoices$/);
      await expect(row(page, '20260004')).toContainText('Example Company s.r.o.');
    }
  });

  test('save and send opens the send dialog over the saved form, Back and the cache never reopen it', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/invoices/new');
    await page.locator('#customer').fill('Example Buy');
    await page.getByRole('option', { name: 'Example Buyer a.s.' }).click();
    await page.locator('#lines-0-text').fill('Konzultace');
    await page.locator('#lines-0-price').fill('1500');
    await page.locator('#invoice-more').click();
    await page.getByRole('menuitem', { name: 'Odeslat a uložit' }).click();

    const dialog = page.getByRole('dialog', { name: 'Odeslat fakturu 20260005' });
    await settled(dialog);
    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]+\/edit$/);
    await expect(page.getByText('Faktura 20260005 je uložená.')).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/dialog-save-send-desktop.png` });
    // Cancel keeps the saved form; a reload shows it without the dialog.
    await dialog.getByRole('button', { name: 'Zrušit' }).click();
    await expect(page.locator('#invoice-save')).toBeFocused();
    await page.reload();
    await expect(page.locator('dialog[open]')).toHaveCount(0);

    // A dialog over a Turbo page closes before the page is cached: Back and Forward show no dialog.
    await page.goto('/invoices');
    await fromMenu(page, '20260005', 'Odeslat');
    await settled(page.getByRole('dialog'));
    await page.goBack();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await page.goForward();
    await expect(page).toHaveURL(/\/invoices$/);
    await expect(page.locator('tbody tr')).not.toHaveCount(0);
    await expect(page.locator('dialog[open]')).toHaveCount(0);
  });
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('every dialog URL is a page of its own', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/invoices');
    const send = await row(page, '20260002').locator('a[role="menuitem"]', { hasText: 'Odeslat' }).getAttribute('href');
    expect(send).not.toBeNull();

    await page.goto(send ?? '');
    await expect(page.getByRole('heading', { level: 1, name: 'Odeslat fakturu 20260002' })).toBeVisible();
    await expect(page.locator('#subject')).toBeVisible();
    await page.goto((send ?? '').replace('/send', '/history'));
    await expect(page.getByRole('heading', { level: 1, name: 'Historie faktury 20260002' })).toBeVisible();

    await page.goto('/invoices');
    await page.getByRole('link', { name: /Vše/ }).first().click();
    const trash = await page.locator('tbody tr a[role="menuitem"]', { hasText: 'Smazat' }).first().getAttribute('href');
    await page.goto(trash ?? '');
    await expect(page.getByRole('heading', { level: 1, name: /^Smazat fakturu/ })).toBeVisible();
    await expect(page.locator('#trash-reason')).toBeVisible();

    await page.goto('/contacts/new');
    await expect(page.getByRole('heading', { level: 1, name: 'Nový kontakt' })).toBeVisible();
    await expect(page.locator('#phone')).toBeVisible();
  });
});
