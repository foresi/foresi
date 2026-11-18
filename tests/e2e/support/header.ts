// SPDX-License-Identifier: AGPL-3.0-only
import type { Locator, Page } from '@playwright/test';

/**
 * Opens the "⋯" more-actions menu of the record in front, a sheet's header or the page header, and
 * returns it. A sheet comes later in the document than the page under it, so the last visible
 * trigger is the one in front.
 */
export async function openMoreActions(page: Page): Promise<Locator> {
  const menu = page.locator('[data-more-actions-menu]').filter({ has: page.locator('[aria-haspopup="menu"]:visible') }).last();
  await menu.locator('[aria-haspopup="menu"]').click();
  return menu.locator('[role="menu"]');
}
