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

/**
 * Fields side by side in one grid row start their controls at the same height
 * (±1 px), whatever label, help or error text sits next to them. Returns the
 * rows that do not, so a failure names the fields.
 */
export async function expectRowControlsAligned(scope: Locator): Promise<void> {
  const misaligned = await scope.evaluate((root) => {
    const control = (cell: Element): Element | null =>
      cell.querySelector('input:not([type=hidden]):not(.sr-only), select, textarea, label:has(> input.sr-only)');
    const problems: string[] = [];
    for (const grid of root.querySelectorAll('.grid')) {
      const rows = new Map<number, Element[]>();
      for (const cell of grid.children) {
        if (!control(cell) || (cell as HTMLElement).offsetParent === null) {
          continue;
        }
        const top = Math.round(cell.getBoundingClientRect().top);
        rows.set(top, [...(rows.get(top) ?? []), cell]);
      }
      for (const cells of rows.values()) {
        const tops = cells.map((cell) => control(cell)!.getBoundingClientRect().top);
        if (Math.max(...tops) - Math.min(...tops) > 1) {
          problems.push(cells.map((cell, i) => `${control(cell)!.id || control(cell)!.tagName} at ${tops[i].toFixed(1)}`).join(' vs '));
        }
      }
    }
    return problems;
  });
  expect(misaligned, 'controls in one row start at different heights').toEqual([]);
}
