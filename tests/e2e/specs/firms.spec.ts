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

async function fillSeat(page: Page): Promise<void> {
  await page.locator('#street').fill('Example Street 1');
  await page.locator('#city').fill('Example City');
  await page.locator('#postalCode').fill('100 00');
}

test.describe('first run', () => {
  test.use({ storageState: EMPTY_OWNER_STATE });

  test('an organisation without firms is told how to start', async ({ page }) => {
    await page.goto('/settings/firms');
    await expect(page.getByRole('heading', { level: 1, name: 'Firmy' })).toBeVisible();
    await expect(page.getByText('Zatím tu nejsou žádné firmy')).toBeVisible();
    await expectNoSeriousViolations(page);
  });
});

// The steps build on each other: the first firms are what the later ones find in the list.
test.describe.serial('an owner manages firms', () => {
  test.use({ storageState: OWNER_STATE });

  test('reaches them from the sidebar', async ({ page }) => {
    await page.goto('/contacts');
    await page.locator('#sidebar').getByRole('link', { name: 'Firmy' }).click();
    await expect(page).toHaveURL(/\/settings\/firms$/);
  });

  test('creates a firm from the register', async ({ page }) => {
    await page.goto('/settings/firms/new');
    await page.locator('#lookup').fill('12345687');
    await page.getByRole('button', { name: /Example Company s\.r\.o\./ }).click();

    await expect(page.locator('#name')).toHaveValue('Example Company s.r.o.');
    await expect(page.locator('#registrationNumber')).toHaveValue('12345687');
    await expect(page.locator('#vatId')).toHaveValue('CZ12345687');
    await page.locator('#registerEntry').fill('Spisová značka C 1234');
    await page.locator('#bankAccounts').fill('CZ65 0800 0000 1920 0014 5399');
    await expect(page.locator('[data-action-bar]')).toBeInViewport();
    await page.getByRole('button', { name: 'Uložit' }).click();

    await expect(page).toHaveURL(/\/settings\/firms$/);
    await expect(page.getByText('Firma je uložená.')).toBeVisible();
    await expect(page.locator('tbody')).toContainText('Example Company s.r.o.');
  });

  test('saves a non-payer without a VAT number', async ({ page }) => {
    await page.goto('/settings/firms/new');
    await page.locator('#name').fill('Example Trader');
    await page.locator('#registrationNumber').fill('12345695');
    await page.locator('#vatStatus').selectOption('not_registered');
    await fillSeat(page);
    await page.getByRole('button', { name: 'Uložit' }).click();

    await expect(page.locator('tbody')).toContainText('Example Trader');
    await expect(page.locator('tbody')).toContainText('Neplátce DPH');
  });

  test('cannot give two active firms one number', async ({ page }) => {
    await page.goto('/settings/firms/new');
    await page.locator('#name').fill('Second Firm');
    await page.locator('#registrationNumber').fill('12345695');
    await page.locator('#vatStatus').selectOption('not_registered');
    await fillSeat(page);
    await page.getByRole('button', { name: 'Uložit' }).click();

    await expect(page.getByText('Toto IČO už má jiná firma.')).toBeVisible();
    await expect(page.locator('#name')).toHaveValue('Second Firm');
    await expect(page.locator('#registrationNumber')).toBeFocused();
  });

  test('edits a firm', async ({ page }) => {
    await page.goto('/settings/firms');
    await page.getByRole('link', { name: 'Example Trader' }).click();
    await expect(page.locator('#lookup')).toHaveCount(0);
    await page.locator('#name').fill('Example Trader Renamed');
    await page.getByRole('button', { name: 'Uložit' }).click();

    await expect(page.getByText('Změny jsou uložené.')).toBeVisible();
    await expect(page.locator('tbody')).toContainText('Example Trader Renamed');
  });

  test('archives a firm, which releases its number', async ({ page }) => {
    await page.goto('/settings/firms');
    await page.getByRole('link', { name: 'Example Trader Renamed' }).click();
    await page.getByRole('button', { name: 'Archivovat' }).click();

    await expect(page.getByText('Firma je archivovaná.')).toBeVisible();
    await expect(page.locator('tbody')).not.toContainText('Example Trader Renamed');
    await page.getByRole('link', { name: /Archivované/ }).click();
    await expect(page.locator('tbody')).toContainText('Example Trader Renamed');
  });

  test('has no serious accessibility violations @a11y', async ({ page }) => {
    await page.goto('/settings/firms');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expectNoSeriousViolations(page);
    await page.goto('/settings/firms/new');
    await expect(page.locator('#name')).toBeVisible();
    await expectNoSeriousViolations(page);
  });

  test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('neither screen scrolls sideways @layout', async ({ page }) => {
      await page.goto('/settings/firms');
      await expect(page.locator('tbody tr').first()).toBeVisible();
      await expectNoSidewaysScroll(page);
      await page.goto('/settings/firms/new');
      await expect(page.locator('#name')).toBeVisible();
      await expectNoSidewaysScroll(page);
    });
  });

  // Fixme until Martin approves the screens: without a baseline the assertion can only fail.
  // Drop the fixme and generate the baselines with --update-snapshots after the approval.
  test.fixme('the list looks as approved @visual', async ({ page }) => {
    await page.goto('/settings/firms');
    await expect(page).toHaveScreenshot('list.png');
  });

  test.fixme('the form looks as approved @visual', async ({ page }) => {
    await page.goto('/settings/firms/new');
    await expect(page).toHaveScreenshot('form.png');
  });
});
