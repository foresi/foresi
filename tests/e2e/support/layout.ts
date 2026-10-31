// SPDX-License-Identifier: AGPL-3.0-only

import { expect, type Locator, type Page } from '@playwright/test';

/**
 * The focused control of a form sent back with errors is in the viewport and
 * not under the floating action bar (their boxes do not overlap).
 */
export async function expectFocusedClearOfActionBar(page: Page, control: Locator): Promise<void> {
  await expect(control).toBeFocused();
  await expect(control).toBeInViewport({ ratio: 1 });
  const bar = page.locator('[data-action-bar]');
  const [box, barBox] = await Promise.all([control.boundingBox(), bar.boundingBox()]);
  expect(box).not.toBeNull();
  expect(barBox).not.toBeNull();
  const clear = box!.y + box!.height <= barBox!.y || box!.y >= barBox!.y + barBox!.height || box!.x + box!.width <= barBox!.x || box!.x >= barBox!.x + barBox!.width;
  expect(clear, `the focused control (${JSON.stringify(box)}) is under the action bar (${JSON.stringify(barBox)})`).toBe(true);
}
