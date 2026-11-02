// SPDX-License-Identifier: AGPL-3.0-only

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Written by tests/e2e/seed.php: the links of the seeded send, derived from the e2e secret.
const links = JSON.parse(readFileSync(resolve(__dirname, '../../../var/e2e-links.json'), 'utf8')) as { customer: string; copy: string; number: string };
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const SHOTS = process.env.E2E_SHOTS;

async function expectNoSeriousViolations(page: Page): Promise<void> {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  const blocking = violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
  expect(blocking.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

// How often the customer opened the PDF, read from the e2e database with PHP, which every runner has.
function readCount(): number {
  const read = `$s = new PDO('sqlite:'.$argv[1])->prepare('SELECT read_count FROM invoice_send WHERE token_hash = ?'); $s->execute([$argv[2]]); echo $s->fetchColumn();`;
  const hash = createHash('sha256').update(links.customer).digest('hex');
  return Number(execFileSync('php', ['-r', read, resolve(__dirname, '../../../var/e2e.db'), hash]).toString());
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

// The customer opens the link from a mail: no session, no sign-in.
test.use({ storageState: { cookies: [], origins: [] } });

// The page embeds the PDF on wide screens; the browser loading it is no person reading it.
test('the embedded PDF alone never counts as read, the Open PDF button does', async ({ page, browserName }) => {
  const before = readCount();
  const embedded = page.waitForResponse((r) => r.url().endsWith(`/d/${links.customer}/pdf`), { timeout: 2000 }).catch(() => null);
  await page.goto(`/d/${links.customer}`);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  const embed = await embedded;
  test.info().annotations.push({ type: 'embed', description: `${browserName}: ${embed ? `loaded the PDF (${embed.status()})` : 'did not load the PDF'}` });
  await page.waitForLoadState('networkidle');
  expect(readCount()).toBe(before);

  await Promise.all([page.waitForResponse((r) => r.url().endsWith(`/d/${links.customer}/open`)), page.getByRole('link', { name: 'Otevřít PDF' }).first().click()]);
  await expect.poll(readCount).toBe(before + 1);
});

test('the link shows the document and opens its PDF from the keyboard @a11y @layout', async ({ page }, testInfo) => {
  await page.goto(`/d/${links.customer}`);

  await expect(page.getByRole('heading', { level: 1, name: `Faktura ${links.number}` })).toBeVisible();
  await expect(page.getByText(/^15\s125,00\sKč$/).first()).toBeVisible();
  await expect(page.getByText('16. 11. 2026')).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: 'Platba převodem' })).toBeVisible();
  await expect(page.getByText('Example Studio s.r.o.').first()).toBeVisible();
  await expectNoSeriousViolations(page);
  await expectNoSidewaysScroll(page);
  if (SHOTS) {
    await page.screenshot({ path: `${SHOTS}/public-document-${testInfo.project.name}.png`, fullPage: true });
    await page.screenshot({ path: `${SHOTS}/public-document-${testInfo.project.name}-fold.png` });
  }

  // The first stop is the way to the PDF, and Enter opens it.
  await page.keyboard.press('Tab');
  const open = page.getByRole('link', { name: 'Otevřít PDF' }).first();
  await expect(open).toBeFocused();
  const [response] = await Promise.all([page.waitForResponse((r) => r.url().endsWith(`/d/${links.customer}/open`)), page.keyboard.press('Enter')]);
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toBe('application/pdf');
});

test('an unknown link shows a neutral page @a11y @layout', async ({ page }, testInfo) => {
  const response = await page.goto(`/d/${'A'.repeat(43)}`);

  expect(response?.status()).toBe(404);
  await expect(page.getByRole('heading', { level: 1, name: 'Odkaz není k dispozici' })).toBeVisible();
  await expect(page.getByText('Example Studio')).toHaveCount(0);
  await expectNoSeriousViolations(page);
  await expectNoSidewaysScroll(page);
  if (SHOTS) {
    await page.screenshot({ path: `${SHOTS}/public-gone-${testInfo.project.name}.png`, fullPage: true });
  }
});
