// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '../support/test';
import { PROJECTS_OWNER_STATE } from '../support/session';

// The signed-in owner of "Example Projects" in tests/e2e/seed.php. The project is made here, so the
// seeded "Website" keeps the look its screenshots approve.
test.use({ storageState: PROJECTS_OWNER_STATE });

test('the DUD case: eight approved hours become the budget in the header', async ({ page }) => {
  await page.goto('/projects');
  await page.getByRole('link', { name: 'Nový projekt' }).first().click();
  const created = page.getByRole('dialog');
  await created.getByLabel('Název').fill('DUD');
  await created.getByRole('button', { name: 'Založit projekt' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('DUD');

  await page.locator('[data-project-tabs]').getByRole('link', { name: 'Rozpočet' }).click();
  const tab = page.locator('[data-project-budget-section]');
  await expect(tab).toContainText('Zatím žádný časový rozpočet');
  await tab.locator('[data-budget-add]').click();
  const dialog = page.getByRole('dialog', { name: 'Přidat změnu' });
  await dialog.getByLabel('Hodiny').fill('8');
  await dialog.getByLabel('Poznámka').fill('8 h of DUD development approved');
  await dialog.getByRole('button', { name: 'Uložit' }).click();
  await expect(dialog).toBeHidden();

  await expect(page).toHaveURL(/\?tab=budget$/);
  await expect(page.locator('[data-project-facts] [data-project-budget]')).toHaveText(/Rozpočet 8 h · čerpáno 0 h · zbývá 8 h/);
  await expect(page.locator('[data-budget-changes]')).toContainText('8 h of DUD development approved');
});
