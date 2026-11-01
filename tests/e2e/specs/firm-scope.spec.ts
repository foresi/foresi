// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { GROUP_OWNER_STATE, OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const FIRM = 'Example Trading s.r.o.';
const OTHER_FIRM = 'Example International Services and Holding s.r.o.';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

async function openContact(page: Page): Promise<void> {
  await page.goto('/contacts');
  await page.getByRole('link', { name: 'Example Partner s.r.o.' }).click();
  await page.locator('main').getByRole('link', { name: 'Upravit' }).click();
  await expect(page.locator('[data-firm-panel="roles"]')).toBeVisible();
}

function panel(page: Page, key: string): Locator {
  return page.locator(`[data-firm-panel="${key}"]`);
}

async function choose(section: Locator, firm: string): Promise<void> {
  await section.getByRole('button', { name: /Platí pro/ }).click();
  await section.getByRole('menuitemradio', { name: new RegExp(firm.replace(/[.]/g, '\\.')) }).click();
}

function ownMarker(section: Locator, firm: string): Locator {
  return section.getByRole('menuitemradio', { name: new RegExp(firm.replace(/[.]/g, '\\.')) }).locator('[data-firm-scope-target="marker"]');
}

test.describe('with no second firm', () => {
  test.use({ storageState: OWNER_STATE });

  test('the contact form has no scope', async ({ page }) => {
    await page.goto('/contacts/new');
    await expect(page.locator('#displayName')).toBeVisible();
    await expect(page.locator('[data-firm-panel]')).toHaveCount(0);
  });
});

// The steps build on each other: the override saved first is what the later ones find.
test.describe.serial('an owner of two firms', () => {
  test.use({ storageState: GROUP_OWNER_STATE });

  test('sees the shared values read only for a firm', async ({ page }) => {
    await openContact(page);
    const roles = panel(page, 'roles');
    await expect(roles.getByRole('button', { name: /Platí pro/ })).toContainText('Všechny firmy');

    await page.getByLabel('Zákazník', { exact: true }).first().uncheck({ force: true });
    await page.getByLabel('Dodavatel', { exact: true }).first().check({ force: true });
    await choose(roles, FIRM);

    await expect(roles.getByRole('button', { name: /Platí pro/ })).toContainText(FIRM);
    const pane = roles.locator('[data-firm-scope-target="pane"]:visible');
    await expect(pane.getByRole('checkbox', { name: 'Dodavatel' })).toBeDisabled();
    await expect(pane.getByRole('checkbox', { name: 'Dodavatel' })).toBeChecked();
    await expect(pane.getByRole('button', { name: 'Nastavit pro tuto firmu' })).toBeVisible();
    await expect(pane.getByRole('button', { name: 'Vrátit na společné' })).toBeHidden();
  });

  test('sets, edits and saves values for one firm', async ({ page }) => {
    await openContact(page);
    const roles = panel(page, 'roles');
    await choose(roles, FIRM);
    const pane = roles.locator('[data-firm-scope-target="pane"]:visible');
    await pane.getByRole('button', { name: 'Nastavit pro tuto firmu' }).click();

    await expect(pane.getByRole('checkbox', { name: 'Zákazník' })).toBeEnabled();
    await expect(pane.getByRole('checkbox', { name: 'Zákazník' })).toBeChecked();
    await expect(roles.locator('[data-firm-scope-target="currentMarker"]')).toBeVisible();
    await pane.getByRole('checkbox', { name: 'Zákazník' }).uncheck({ force: true });
    await pane.getByRole('checkbox', { name: 'Dodavatel' }).check({ force: true });

    const invoicing = panel(page, 'invoicing');
    await choose(invoicing, OTHER_FIRM);
    const invoicingPane = invoicing.locator('[data-firm-scope-target="pane"]:visible');
    await invoicingPane.getByRole('button', { name: 'Nastavit pro tuto firmu' }).click();
    await expect(invoicingPane.getByLabel('Splatnost ve dnech')).toHaveValue('14');
    await invoicingPane.getByLabel('Splatnost ve dnech').fill('45');

    await page.getByRole('button', { name: 'Uložit' }).click();
    await expect(page.getByText('Změny jsou uloženy.')).toBeVisible();

    await openContact(page);
    await expect(roles.locator('[data-firm-scope-target="pane"]:visible').getByRole('checkbox', { name: 'Zákazník' })).toBeChecked();
    await roles.getByRole('button', { name: /Platí pro/ }).click();
    await expect(ownMarker(roles, FIRM)).toBeVisible();
    await expect(ownMarker(roles, 'Holding')).toBeHidden();
    await expect(roles.locator('[data-firm-scope-target="pane"]:visible')).toContainText(FIRM);
    await roles.getByRole('menuitemradio', { name: new RegExp(FIRM.replace('.', '\\.')) }).click();
    const own = roles.locator('[data-firm-scope-target="pane"]:visible');
    await expect(own.getByRole('checkbox', { name: 'Dodavatel' })).toBeChecked();
    await expect(own.getByRole('checkbox', { name: 'Zákazník' })).not.toBeChecked();
  });

  test('works the dropdown with the keyboard alone, and it is accessible', async ({ page }) => {
    await openContact(page);
    const roles = panel(page, 'roles');
    const button = roles.getByRole('button', { name: /Platí pro/ });
    await button.focus();
    await page.keyboard.press('ArrowDown');
    await expect(roles.getByRole('menuitemradio', { name: 'Všechny firmy' })).toBeFocused();
    await expectNoSeriousViolations(page);

    await page.keyboard.press('Escape');
    await expect(button).toBeFocused();
    await expect(roles.getByRole('menu')).toBeHidden();

    await page.keyboard.press('Enter');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(button).toBeFocused();
    await expect(button).toContainText(FIRM);
    await expect(roles.getByRole('button', { name: 'Vrátit na společné' })).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test('goes back to shared after a confirmation, and cancelling keeps the values', async ({ page }) => {
    await openContact(page);
    const roles = panel(page, 'roles');
    await choose(roles, FIRM);
    const pane = roles.locator('[data-firm-scope-target="pane"]:visible');

    page.once('dialog', (dialog) => dialog.dismiss());
    await pane.getByRole('button', { name: 'Vrátit na společné' }).click();
    await expect(pane.getByRole('checkbox', { name: 'Dodavatel' })).toBeEnabled();

    let message = '';
    page.once('dialog', (dialog) => {
      message = dialog.message();
      void dialog.accept();
    });
    await pane.getByRole('button', { name: 'Vrátit na společné' }).click();
    await expect(pane.getByRole('button', { name: 'Nastavit pro tuto firmu' })).toBeVisible();
    await expect(pane.getByRole('checkbox', { name: 'Dodavatel' })).toBeDisabled();
    expect(message).toContain('smažou');

    await page.getByRole('button', { name: 'Uložit' }).click();
    await expect(page.getByText('Změny jsou uloženy.')).toBeVisible();

    await openContact(page);
    await roles.getByRole('button', { name: /Platí pro/ }).click();
    await expect(ownMarker(roles, FIRM)).toBeHidden();
  });

  test('a wrong value stays on the firm it belongs to', async ({ page }) => {
    await openContact(page);
    const invoicing = panel(page, 'invoicing');
    await choose(invoicing, OTHER_FIRM);
    await invoicing.locator('[data-firm-scope-target="pane"]:visible').getByLabel('Splatnost ve dnech').fill('999');
    await page.getByRole('button', { name: 'Uložit' }).click();

    const field = invoicing.locator('[data-firm-scope-target="pane"]:visible').getByLabel('Splatnost ve dnech');
    await expect(field).toBeFocused();
    await expect(field).toHaveValue('999');
    await expect(invoicing.getByRole('button', { name: /Platí pro/ })).toContainText('Holding');
  });

  test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('fits the screen with the menu open', async ({ page }) => {
      await openContact(page);
      const invoicing = panel(page, 'invoicing');
      await expectNoSidewaysScroll(page);
      await choose(invoicing, OTHER_FIRM);
      await expectNoSidewaysScroll(page);

      await invoicing.getByRole('button', { name: /Platí pro/ }).click();
      const menu = invoicing.getByRole('menu');
      await expect(menu).toBeInViewport({ ratio: 1 });
      await expectNoSidewaysScroll(page);
      await expectNoSeriousViolations(page);
    });
  });
});
