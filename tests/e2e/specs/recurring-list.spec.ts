// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { RECURRING_OWNER_STATE } from '../support/session';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1440, height: 960 };
const PHONE = { width: 390, height: 844 };
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/shots';

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

// A row by the description it shows: every template of the seed has the same customer.
function row(page: Page, description: string): Locator {
  return page.locator('tbody tr').filter({ hasText: description });
}

// The dialog is laid out and its first frame painted, so a screenshot shows it.
async function settled(dialog: Locator): Promise<void> {
  await expect(dialog).toBeVisible();
  await dialog.page().evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

test.use({ storageState: RECURRING_OWNER_STATE });

test.describe.serial('recurring list', () => {
  test('lists templates by state with counts, from the sidebar @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices');
      if (name === 'desktop') {
        await page.locator('#sidebar').getByRole('link', { name: 'Pravidelné faktury' }).click();
        await expect(page).toHaveURL(/\/invoices\/recurring$/);
      } else {
        await page.goto('/invoices/recurring');
      }
      await expect(page.getByRole('heading', { level: 1, name: 'Pravidelné faktury' })).toBeVisible();
      const tabs = page.getByRole('navigation', { name: 'Seznamy pravidelných faktur' });
      await expect(tabs.getByRole('link')).toHaveText([/Aktivní\s*5/, /Pozastavené\s*1/, /Ukončené\s*1/, /Selhaly\s*1/]);
      await expect(row(page, 'Domain renewal')).toContainText('Ročně');
      await expect(row(page, 'Support licence')).toContainText(name === 'desktop' ? 'Každých 12 měsíců' : 'Support licence');
      await page.screenshot({ path: `${SHOTS}/recurring-list-${name}.png`, fullPage: true });
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);

      await page.goto('/invoices/recurring?tab=failing');
      const failing = row(page, 'Monitoring');
      await expect(failing).toBeVisible();
      // The description stays, and the reason is a line of its own with the way to fix it.
      const alert = failing.locator(name === 'desktop' ? '[data-row-alert]' : '[data-row-alert-phone]');
      await expect(alert).toBeVisible();
      await expect(alert).toContainText('Odběratel potřebuje úplnou fakturační adresu.');
      await expect(alert.getByRole('link', { name: 'Upravit kontakt' })).toHaveAttribute('href', /^\/contacts\//);
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/recurring-failing-${name}.png`, fullPage: true });
    }
  });

  test('the review notice leads to the invoices waiting to be sent @a11y', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices?toSend=1');
      const chip = page.locator('[data-to-send] a');
      await expect(chip).toHaveAttribute('aria-current', 'true');
      await expect(chip).toContainText('K odeslání');
      await expect(page.locator('tbody tr')).toHaveCount(1);
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/invoices-to-send-${name}.png`, fullPage: true });
    }
  });

  test('resume and issue now state what they will do @a11y @layout', async ({ page }) => {
    for (const [name, size] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
      await page.setViewportSize(size);
      await page.goto('/invoices/recurring?tab=paused');
      await row(page, 'Backup storage').getByRole('button', { name: /Další akce/ }).click();
      await row(page, 'Backup storage').getByRole('menuitem', { name: 'Obnovit', exact: true }).click();
      const resume = page.getByRole('dialog', { name: 'Obnovit pravidelnou fakturu' });
      await settled(resume);
      await expect(resume.locator('[data-resumes-on]')).toContainText('Další faktura se vystaví');
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/recurring-resume-${name}.png` });
      await page.keyboard.press('Escape');
      await expect(resume).toBeHidden();

      await page.goto('/invoices/recurring');
      await row(page, 'Hosting').getByRole('button', { name: /Další akce/ }).click();
      await row(page, 'Hosting').getByRole('menuitem', { name: 'Vystavit nyní', exact: true }).click();
      const issue = page.getByRole('dialog', { name: 'Vystavit fakturu nyní' });
      await settled(issue);
      await expect(issue).toContainText('Datum vystavení');
      await expect(issue.locator('[data-delivery]')).toContainText('odběrateli');
      await expect(issue.getByRole('button', { name: 'Vystavit', exact: true })).toBeFocused();
      await expectNoSeriousViolations(page);
      await expectNoSidewaysScroll(page);
      await page.screenshot({ path: `${SHOTS}/recurring-issue-now-${name}.png` });
      await page.keyboard.press('Escape');
      await expect(issue).toBeHidden();
    }
  });

  test('the keyboard pauses a template from the row menu and focus comes back', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto('/invoices/recurring');
    const opener = row(page, 'Domain renewal').getByRole('button', { name: /Další akce/ });
    await opener.focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menuitem', { name: 'Upravit', exact: true }).locator('visible=true')).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menuitem', { name: 'Vystavit nyní', exact: true }).locator('visible=true')).toBeFocused();
    await page.keyboard.press('ArrowDown');
    const pause = page.getByRole('menuitem', { name: 'Pozastavit', exact: true }).locator('visible=true');
    await expect(pause).toBeFocused();
    await pause.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Pozastavit pravidelnou fakturu' });
    await settled(dialog);
    await expect(dialog.getByRole('button', { name: 'Pozastavit', exact: true })).toBeFocused();
    await page.screenshot({ path: `${SHOTS}/recurring-pause-desktop.png` });

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(opener).toBeFocused();

    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await expect(pause).toBeFocused();
    await page.keyboard.press('Enter');
    await settled(dialog);
    await page.keyboard.press('Enter');
    await expect(page.locator('[role=status]').filter({ hasText: 'je pozastavená' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Seznamy pravidelných faktur' }).getByRole('link')).toHaveText([/Aktivní\s*4/, /Pozastavené\s*2/, /Ukončené\s*1/, /Selhaly\s*1/]);
    await expect(row(page, 'Domain renewal')).toHaveCount(0);
  });
});
