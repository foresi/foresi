// SPDX-License-Identifier: AGPL-3.0-only
import type { Locator, Page } from '@playwright/test';

/** Opens the page header's "⋯" more-actions menu and returns it. */
export async function openMoreActions(page: Page): Promise<Locator> {
  await page.locator('main header [data-more-actions-menu] [aria-haspopup="menu"]').click();
  return page.locator('main header [data-more-actions-menu] [role="menu"]');
}
