// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { EMPTY_OWNER_STATE, OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

async function createFirmFromRegister(page: Page): Promise<void> {
  await page.goto('/settings/firm');
  await page.locator('#lookup').fill('12345687');
  await page.getByRole('button', { name: /Example Company s\.r\.o\./ }).click();
  await expect(page.locator('#name')).toHaveValue('Example Company s.r.o.');
}

test.describe('first run', () => {
  test.use({ storageState: EMPTY_OWNER_STATE });

  test('an organisation without a firm is asked to set it up', async ({ page }) => {
    await page.goto('/settings/firm');
    await expect(page.getByRole('heading', { level: 1, name: 'Nastavit firmu' })).toBeVisible();
    await expect(page.locator('#lookup')).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  // Only this world has no firm, so only here the setup form opens; nothing below saves it.
  test('the form has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/settings/firm');
    await expect(page.locator('#name')).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('the form does not scroll sideways @layout', async ({ page }) => {
      await page.goto('/settings/firm');
      await expect(page.locator('#name')).toBeVisible();
      await expectNoSidewaysScroll(page);
    });
  });

  test('the form looks as approved @visual', async ({ page }) => {
    // Only the form: the sidebar counts depend on what ran before.
    await page.goto('/settings/firm');
    await expect(page.locator('form:has(#name)')).toHaveScreenshot('form.png');
  });
});

// The steps build on each other: the firm set up first is what the later ones find on the page.
test.describe.serial('an owner sets up the one firm', () => {
  test.use({ storageState: OWNER_STATE });

  test('reaches it from the settings area', async ({ page }) => {
    await page.goto('/contacts');
    await page.locator('#rail').getByRole('link', { name: 'Nastavení' }).click();
    await page.locator('#sidebar').getByRole('link', { name: 'Firma', exact: true }).click();
    await expect(page).toHaveURL(/\/settings\/firm$/);
  });

  test('creates the firm from the register', async ({ page }) => {
    await createFirmFromRegister(page);
    await expect(page.locator('#registrationNumber')).toHaveValue('12345687');
    await expect(page.locator('#vatId')).toHaveValue('CZ12345687');
    await page.locator('#registerEntry').fill('Spisová značka C 1234');
    await expect(page.locator('[data-action-bar]')).toBeInViewport();
    await page.getByRole('button', { name: 'Uložit' }).click();

    await expect(page).toHaveURL(/\/settings\/firm$/);
    await expect(page.getByText('Firma je uložená.')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'Firma' })).toBeVisible();
    await expect(page.locator('#name')).toHaveValue('Example Company s.r.o.');
  });

  test('offers no second firm', async ({ page }) => {
    await page.goto('/settings/firm');
    await expect(page.getByRole('link', { name: 'Nová firma' })).toHaveCount(0);
    await expect(page.locator('#lookup')).toHaveCount(0);
  });

  test('the old address of the list lands on the firm', async ({ page }) => {
    await page.goto('/settings/firms');
    await expect(page).toHaveURL(/\/settings\/firm$/);
    await expect(page.locator('#name')).toHaveValue('Example Company s.r.o.');
  });

  test('edits the firm', async ({ page }) => {
    await page.goto('/settings/firm');
    await expect(page.locator('#lookup')).toHaveCount(0);
    await page.locator('#registerEntry').fill('Spisová značka C 5678');
    await page.getByRole('button', { name: 'Uložit' }).click();

    await expect(page).toHaveURL(/\/settings\/firm$/);
    await expect(page.getByText('Změny jsou uložené.')).toBeVisible();
    await expect(page.locator('#name')).toHaveValue('Example Company s.r.o.');
    await expect(page.locator('#registerEntry')).toHaveValue('Spisová značka C 5678');
  });

  test('has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/settings/firm');
    await expect(page.locator('#name')).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('the page does not scroll sideways @layout', async ({ page }) => {
      await page.goto('/settings/firm');
      await expect(page.locator('#name')).toBeVisible();
      await expectNoSidewaysScroll(page);
    });
  });
});
