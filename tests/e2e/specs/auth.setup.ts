// SPDX-License-Identifier: AGPL-3.0-only

import { test as setup } from '@playwright/test';
import { EMPTY_OWNER, EMPTY_OWNER_STATE, OWNER, OWNER_STATE, signIn } from '../support/session';

setup('sign in the owner of Example Company', async ({ page }) => {
  await signIn(page, OWNER);
  await page.context().storageState({ path: OWNER_STATE });
});

setup('sign in the owner of the empty tenant', async ({ page }) => {
  await signIn(page, EMPTY_OWNER);
  await page.context().storageState({ path: EMPTY_OWNER_STATE });
});
