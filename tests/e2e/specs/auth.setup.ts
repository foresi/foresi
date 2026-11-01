// SPDX-License-Identifier: AGPL-3.0-only

import { test as setup } from '@playwright/test';
import { EMPTY_OWNER, EMPTY_OWNER_STATE, GROUP_OWNER, GROUP_OWNER_STATE, INVOICING_OWNER, INVOICING_OWNER_STATE, OWNER, OWNER_STATE, signIn } from '../support/session';

setup('sign in the owner of Example Company', async ({ page }) => {
  await signIn(page, OWNER);
  await page.context().storageState({ path: OWNER_STATE });
});

setup('sign in the owner of the empty tenant', async ({ page }) => {
  await signIn(page, EMPTY_OWNER);
  await page.context().storageState({ path: EMPTY_OWNER_STATE });
});

setup('sign in the owner of the tenant with two firms', async ({ page }) => {
  await signIn(page, GROUP_OWNER);
  await page.context().storageState({ path: GROUP_OWNER_STATE });
});

setup('sign in the owner of the tenant that issues invoices', async ({ page }) => {
  await signIn(page, INVOICING_OWNER);
  await page.context().storageState({ path: INVOICING_OWNER_STATE });
});
