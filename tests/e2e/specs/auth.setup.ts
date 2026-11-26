// SPDX-License-Identifier: AGPL-3.0-only

import { test as setup } from '../support/test';
import { EMPTY_OWNER, EMPTY_OWNER_STATE, INVOICING_OWNER, INVOICING_OWNER_STATE, OWNER, OWNER_STATE, signIn, FIRST_RUN_OWNER, FIRST_RUN_OWNER_STATE, SENDING_OWNER, SENDING_OWNER_STATE, DIALOGS_OWNER, DIALOGS_OWNER_STATE, RECURRING_OWNER, RECURRING_OWNER_STATE, REMINDERS_OWNER, REMINDERS_OWNER_STATE, FIRM_MAIL_OWNER, FIRM_MAIL_OWNER_STATE, BANK_OWNER, BANK_OWNER_STATE, PROJECTS_OWNER, PROJECTS_OWNER_STATE, PROJECTS_GUEST, PROJECTS_GUEST_STATE, PURCHASING_OWNER, PURCHASING_OWNER_STATE } from '../support/session';

setup('sign in the owner of Example Company', async ({ page }) => {
  await signIn(page, OWNER);
  await page.context().storageState({ path: OWNER_STATE });
});

setup('sign in the owner of the empty organisation', async ({ page }) => {
  await signIn(page, EMPTY_OWNER);
  await page.context().storageState({ path: EMPTY_OWNER_STATE });
});

setup('sign in the owner of the organisation that issues invoices', async ({ page }) => {
  await signIn(page, INVOICING_OWNER);
  await page.context().storageState({ path: INVOICING_OWNER_STATE });
});

setup('sign in the owner of the organisation that starts from nothing', async ({ page }) => {
  await signIn(page, FIRST_RUN_OWNER);
  await page.context().storageState({ path: FIRST_RUN_OWNER_STATE });
});

setup('sign in the owner of the organisation that sends invoices', async ({ page }) => {
  await signIn(page, SENDING_OWNER);
  await page.context().storageState({ path: SENDING_OWNER_STATE });
});

setup('sign in the owner of the organisation of the dialog specs', async ({ page }) => {
  await signIn(page, DIALOGS_OWNER);
  await page.context().storageState({ path: DIALOGS_OWNER_STATE });
});

setup('sign in the owner of the organisation of the bank settings spec', async ({ page }) => {
  await signIn(page, BANK_OWNER);
  await page.context().storageState({ path: BANK_OWNER_STATE, PROJECTS_OWNER, PROJECTS_OWNER_STATE });
});

setup('sign in the owner of the organisation of the recurring list specs', async ({ page }) => {
  await signIn(page, RECURRING_OWNER);
  await page.context().storageState({ path: RECURRING_OWNER_STATE });
});

setup('sign in the owner of the organisation of the reminder specs', async ({ page }) => {
  await signIn(page, REMINDERS_OWNER);
  await page.context().storageState({ path: REMINDERS_OWNER_STATE });
});

setup('sign in the owner of the organisation of the firm e-mail settings spec', async ({ page }) => {
  await signIn(page, FIRM_MAIL_OWNER);
  await page.context().storageState({ path: FIRM_MAIL_OWNER_STATE });
});

setup('sign in the owner of the organisation of the projects specs', async ({ page }) => {
  await signIn(page, PROJECTS_OWNER);
  await page.context().storageState({ path: PROJECTS_OWNER_STATE });
});

setup('sign in the guest of the client in the organisation of the projects specs', async ({ page }) => {
  await signIn(page, PROJECTS_GUEST);
  await page.context().storageState({ path: PROJECTS_GUEST_STATE });
});

setup('sign in the owner of the organisation of the received documents spec', async ({ page }) => {
  await signIn(page, PURCHASING_OWNER);
  await page.context().storageState({ path: PURCHASING_OWNER_STATE });
});
