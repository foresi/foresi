// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';
import { OWNER_STATE } from '../support/session';

test.use({ storageState: OWNER_STATE });

test('selects rows with the arrow keys and space, and focus stays clear of the bulk bar @layout', async ({ page }) => {
  await page.goto('/contacts');
  const bar = page.getByRole('region', { name: 'Bulk actions' });
  await expect(bar).toBeHidden();
  const rows = page.locator('tbody tr');
  const last = (await rows.count()) - 1;
  expect(last).toBeGreaterThan(20);

  const expectClearOfBar = async (): Promise<void> => {
    const focused = page.locator('tbody input[type=checkbox]:focus');
    await expect(focused).toBeVisible();
    await expect(focused).toBeInViewport();
    const [box, barBox] = await Promise.all([focused.boundingBox(), bar.boundingBox()]);
    expect(box).not.toBeNull();
    expect(barBox).not.toBeNull();
    const clear = box!.y + box!.height <= barBox!.y || box!.y >= barBox!.y + barBox!.height || box!.x + box!.width <= barBox!.x || box!.x >= barBox!.x + barBox!.width;
    expect(clear).toBe(true);
  };

  await page.getByRole('checkbox', { name: 'Select all' }).focus();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Space');
  await expect(bar).toBeVisible();
  await expect(bar).toContainText('1');
  await expectClearOfBar();

  // Select and walk down to the last row: at every step the focused row is visible and never behind the bar.
  for (let row = 1; row <= last; row++) {
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Space');
    await expect(page.locator('tbody input[type=checkbox]:checked')).toHaveCount(row + 1);
    await expectClearOfBar();
  }
  await expect(rows.nth(last).locator('input[type=checkbox]')).toBeFocused();
  await expect(bar).toContainText(String(last + 1));

  // The focus ring is shown for keyboard use.
  expect(await page.locator('tbody input[type=checkbox]:focus').evaluate((element) => element.matches(':focus-visible'))).toBe(true);

  // Every row is selected, so one Space on "Select all" clears the selection and hides the bar.
  await page.getByRole('checkbox', { name: 'Select all' }).focus();
  await page.keyboard.press('Space');
  await expect(bar).toBeHidden();
});

test('the bulk bar actions are reachable and operable by keyboard', async ({ page }) => {
  await page.goto('/contacts');
  await page.getByRole('checkbox', { name: 'Select all' }).focus();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Space');
  const bar = page.getByRole('region', { name: 'Bulk actions' });
  await expect(bar).toBeVisible();

  const archive = bar.getByRole('button', { name: 'Archive' });
  for (let presses = 0; presses < 80; presses++) {
    if (await archive.evaluate((element) => element === document.activeElement)) {
      break;
    }
    await page.keyboard.press('Tab');
  }
  await expect(archive).toBeFocused();
  await expect(archive).toBeInViewport();
});

test('opens the command palette with Ctrl+K and closes it with Escape', async ({ page }) => {
  await page.goto('/contacts');
  const dialog = page.getByRole('dialog', { name: 'Command palette' });
  await expect(dialog).toBeHidden();

  await page.keyboard.press('Control+K');
  await expect(dialog).toBeVisible();
  const input = dialog.getByRole('combobox');
  await expect(input).toBeFocused();

  await input.fill('address');
  await expect(dialog.getByRole('option').first()).toBeVisible();

  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();

  // Pressing the shortcut again toggles it, and Enter follows the chosen command.
  await page.keyboard.press('Control+K');
  await expect(dialog).toBeVisible();
  await input.fill('address');
  await page.keyboard.press('Enter');
  await expect(dialog).toBeHidden();
  await expect(page).toHaveURL(/\/contacts/);
});

test('the view tabs are reachable by keyboard', async ({ page }) => {
  await page.goto('/contacts');
  const suppliers = page.getByRole('navigation', { name: 'Contact views' }).getByRole('link', { name: /^Suppliers/ });
  await suppliers.focus();
  await expect(suppliers).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/view=suppliers/);
});
