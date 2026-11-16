// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '@playwright/test';

// Ui:Sheet on its live catalogue sample: the real host, the real controllers.
test.describe('side sheet', () => {
  test('a detail sheet opens at the right edge, resizes by keyboard and closes back to its opener', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/_catalogue#component-sheet');
    const opener = page.getByRole('link', { name: 'Detail · 640 px' });
    await opener.click();

    const sheet = page.locator('dialog#sheet').and(page.getByRole('dialog', { name: 'Faktura 20260001' }));
    await expect(sheet).toBeVisible();
    expect(await sheet.evaluate((element) => element.matches(':modal'))).toBe(true);
    await expect(sheet.getByRole('heading', { name: 'Faktura 20260001' })).toBeFocused();
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(640);
    await expect.poll(async () => { const box = await sheet.boundingBox(); return box ? Math.round(box.x + box.width) : 0; }).toBe(1440);

    // APG window splitter: the arrow keys move the left edge by 16 px, and the width is remembered.
    const grip = sheet.getByRole('separator', { name: 'Změnit šířku panelu' });
    await grip.focus();
    await page.keyboard.press('ArrowLeft');
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(656);
    await expect(grip).toHaveAttribute('aria-valuenow', '656');
    await page.keyboard.press('Home');
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(420);

    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();
    await expect(opener).toBeFocused();

    await opener.click();
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(420);
    await sheet.getByRole('button', { name: 'Zavřít' }).first().click();
    await expect(sheet).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('a filter sheet is 400 px and has no grip; on a phone a sheet fills the screen', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/_catalogue#component-sheet');
    await page.getByRole('link', { name: 'Filtry · 400 px' }).click();
    const sheet = page.locator('dialog#sheet').and(page.getByRole('dialog', { name: 'Filtry' }));
    await expect(sheet).toBeVisible();
    await expect.poll(async () => (await sheet.boundingBox())?.width).toBe(400);
    await expect(sheet.getByRole('separator')).toHaveCount(0);
    // A click on the scrim closes it.
    await page.mouse.click(200, 450);
    await expect(sheet).toBeHidden();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('link', { name: 'Detail · 640 px' }).click();
    const detail = page.locator('dialog#sheet').and(page.getByRole('dialog', { name: 'Faktura 20260001' }));
    await expect(detail).toBeVisible();
    await expect.poll(async () => (await detail.boundingBox())?.width).toBe(390);
    await expect(detail.getByRole('separator')).toBeHidden();
  });
});
