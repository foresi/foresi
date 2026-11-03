// SPDX-License-Identifier: AGPL-3.0-only

import { test as setup } from '@playwright/test';
import { EMPTY_OWNER, EMPTY_OWNER_STATE, GROUP_OWNER, GROUP_OWNER_STATE, INVOICING_OWNER, INVOICING_OWNER_STATE, OWNER, OWNER_STATE, signIn, FIRST_RUN_OWNER, FIRST_RUN_OWNER_STATE, SENDING_OWNER, SENDING_OWNER_STATE, DIALOGS_OWNER, DIALOGS_OWNER_STATE } from '../support/session';

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

setup('sign in the owner of the tenant that starts from nothing', async ({ page }) => {
  await signIn(page, FIRST_RUN_OWNER);
  await page.context().storageState({ path: FIRST_RUN_OWNER_STATE });
});

setup('sign in the owner of the tenant that sends invoices', async ({ page }) => {
  await signIn(page, SENDING_OWNER);
  await page.context().storageState({ path: SENDING_OWNER_STATE });
});

setup('sign in the owner of the tenant of the dialog specs', async ({ page }) => {
  await signIn(page, DIALOGS_OWNER);
  await page.context().storageState({ path: DIALOGS_OWNER_STATE });
});
