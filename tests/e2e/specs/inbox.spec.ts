// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test } from '../support/test';
import { signIn, worldHeader } from '../support/session';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// The world of this spec in tests/e2e/seed/inbox.php: the client Client A s.r.o. (its address is not
// the sender's below), its deal Roof audit and its one project Warehouse roof. The webhook secret is the one of tests/e2e/serve.sh.
// The seed writes an agent's token (inbox:read and inbox_suggestions:write only) to var/e2e-inbox-agent.json.
const OWNER = 'inbox@example.com';
const WEBHOOK_SECRET = 'e2e-inbound-webhook-secret';
const CLIENT = 'Client A s.r.o.';
const PROJECT = 'Warehouse roof';
const QUOTE = 'could you audit the roof of our warehouse in December?';

function agentToken(): string {
  return (JSON.parse(readFileSync(resolve(__dirname, '../../../var/e2e-inbox-agent.json'), 'utf8')) as { token: string }).token;
}

// A synthetic mail as the receiving server hands it on: the server that is trusted in serve.sh vouches for the sender.
function mailTo(address: string, subject = 'Roof audit for the warehouse', messageId = 'inbox-e2e-1@buyer.example.com'): string {
  return [
    'Authentication-Results: mx.example.com; dkim=none; dmarc=pass header.from=buyer.example.com',
    'From: Buyer <buyer@buyer.example.com>',
    `To: ${address}`,
    `Subject: ${subject}`,
    'Date: Mon, 23 Nov 2026 09:15:00 +0100',
    `Message-ID: <${messageId}>`,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=utf-8',
    '',
    'Hello, could you audit the roof of our warehouse in December?',
    'See https://buyer.example.com/warehouse for the plan.',
    '',
  ].join('\r\n');
}

test.describe('the inbox', () => {
  // In order: the second test sends its mail to the address the first one makes.
  test.describe.configure({ mode: 'serial' });
  let address = '';

  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 960 });
    await signIn(page, OWNER);
  });

  test('a forwarded mail waits under New, is matched to a contact, linked to a deal, marked done and shown on the deal\'s timeline', async ({ page }) => {
    // The address: made in its dialog, shown once in full.
    await page.goto('/account/inbox');
    await page.locator('#inbox-address-new').click();
    const create = page.getByRole('dialog');
    await create.locator('#label').fill('Forwarding from the mail program');
    await create.locator('button[type=submit]').click();
    address = await page.locator('#inbox-address').inputValue();
    expect(address).toMatch(/^bank\+[^@]+@example\.com$/);

    // The relay posts a mail to that address: only the webhook receives mail in this world.
    const answer = await page.request.post('/inbound/mail', {
      headers: { ...worldHeader('inbox'), Authorization: `Bearer ${WEBHOOK_SECRET}`, 'Content-Type': 'message/rfc822' },
      data: mailTo(address),
    });
    expect(answer.status()).toBe(202);

    // It waits under New, with nobody matched, and its text is shown as text with the link spelled out.
    await page.goto('/inbox');
    const list = page.locator('[data-inbox-list]');
    await expect(list).toContainText('Roof audit for the warehouse');
    await list.getByRole('link', { name: 'buyer@buyer.example.com' }).click();
    await expect(page).toHaveURL(/\/inbox\/[0-9a-f-]{36}$/);
    const item = page.url();
    await expect(page.locator('[data-inbox-state]')).toHaveText('Nové');
    await expect(page.locator('[data-inbox-text]')).toContainText('audit the roof of our warehouse');
    await expect(page.locator('[data-inbox-text] a')).toHaveCount(0);

    // Matched to a contact by hand, in the dialog.
    await page.locator('[data-inbox-match]').click();
    const match = page.getByRole('dialog');
    await match.getByRole('combobox').fill('Client A');
    await page.getByRole('listbox').getByRole('option', { name: new RegExp(CLIENT) }).click();
    await match.locator('button[type=submit]').click();
    await expect(page).toHaveURL(item);
    await expect(page.locator('main')).toContainText(CLIENT);

    // Linked to the deal: its id is the last part of its address.
    await page.goto('/sales/deals');
    const deal = (await page.getByRole('link', { name: 'Roof audit' }).first().getAttribute('href')) ?? '';
    const dealId = deal.split('/').pop() ?? '';
    expect(dealId).toMatch(/^[0-9a-f-]{36}$/);
    await page.goto(item);
    await page.locator('[data-inbox-link]').click();
    const link = page.getByRole('dialog');
    await link.locator('#kind').selectOption('deal');
    await link.locator('#recordId').fill(dealId);
    await link.locator('button[type=submit]').click();
    await expect(page.locator('[data-inbox-outputs]')).toContainText('Roof audit');

    // Done: it leaves New and shows under Done.
    await page.locator('[data-inbox-done] button').click();
    await expect(page).toHaveURL(/\/inbox(\?|$)/);
    await expect(page.locator('[data-inbox-empty]')).toBeVisible();
    await expect(page.locator('[data-inbox-list]')).not.toContainText('Roof audit for the warehouse');
    await page.goto('/inbox?view=done');
    await expect(page.locator('[data-inbox-list]')).toContainText('Roof audit for the warehouse');

    // The deal's timeline shows the mail as its e-mail activity, linked to the matched client too.
    await page.goto(deal);
    const email = page.locator('[data-deal-tabs] [data-activities-done] [data-activity]').filter({ hasText: 'Roof audit for the warehouse' });
    await expect(email).toHaveCount(1);
    await expect(email.locator('[data-activity-subject]')).toHaveText('Roof audit for the warehouse');
    await expect(email.locator('[data-activity-links]')).toContainText(CLIENT);
  });

  test('Create task on a mail opens a dialog filled from it, makes the task on the client\'s project and shows the mail on the project\'s Activities tab', async ({ page }) => {
    // The address the test before made: a person has one, shown in full only when it is made.
    expect(address).not.toBe('');
    const answer = await page.request.post('/inbound/mail', {
      headers: { ...worldHeader('inbox'), Authorization: `Bearer ${WEBHOOK_SECRET}`, 'Content-Type': 'message/rfc822' },
      data: mailTo(address, 'Gutter repair before winter', 'inbox-e2e-task@buyer.example.com'),
    });
    expect(answer.status()).toBe(202);

    // Open the item and match it to the client, whose one project Create task then picks.
    await page.goto('/inbox');
    await page.locator('[data-inbox-list] tbody tr').filter({ hasText: 'Gutter repair before winter' }).getByRole('link', { name: 'buyer@buyer.example.com' }).click();
    await expect(page).toHaveURL(/\/inbox\/[0-9a-f-]{36}$/);
    const item = page.url();
    await page.locator('[data-inbox-match]').click();
    const match = page.getByRole('dialog');
    await match.getByRole('combobox').fill('Client A');
    await page.getByRole('listbox').getByRole('option', { name: new RegExp(CLIENT) }).click();
    await match.locator('button[type=submit]').click();
    await expect(page).toHaveURL(item);
    await expect(page.locator('main')).toContainText(CLIENT);

    // The dialog: the subject as the title, the text as the description, the project chosen, Mark done ticked.
    await page.locator('[data-inbox-action="inbox.actions.create_task"]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.locator('#inbox-action-title')).toHaveValue('Gutter repair before winter');
    await expect(dialog.locator('#inbox-action-description')).toHaveValue(/audit the roof of our warehouse/);
    await expect(dialog.locator('#inbox-action-project_id option:checked')).toHaveText(PROJECT);
    await expect(dialog.locator('#inbox-action-mark_done')).toBeChecked();
    await dialog.locator('button[type=submit]').click();

    // Back on the item: done, with the task among what came of it.
    await expect(page).toHaveURL(item);
    await expect(page.locator('[data-inbox-state]')).toHaveText('Hotové');
    await expect(page.locator('[data-inbox-outputs]')).toContainText('Gutter repair before winter');

    // The project's Activities tab shows the mail as its e-mail activity.
    await page.goto('/projects');
    const project = (await page.getByRole('link', { name: PROJECT }).first().getAttribute('href')) ?? '';
    await page.goto(`${project}?tab=activities`);
    const email = page.locator('[data-project-section="activities"] [data-activities-done] [data-activity]').filter({ hasText: 'Gutter repair before winter' });
    await expect(email).toHaveCount(1);
  });

  test('an agent proposes over the API, the card shows the source and the quote, one step is unticked and the rest accepted', async ({ page }) => {
    expect(address).not.toBe('');
    const answer = await page.request.post('/inbound/mail', {
      headers: { ...worldHeader('inbox'), Authorization: `Bearer ${WEBHOOK_SECRET}`, 'Content-Type': 'message/rfc822' },
      data: mailTo(address, 'Roof audit in December', 'inbox-e2e-suggestion@buyer.example.com'),
    });
    expect(answer.status()).toBe(202);
    await page.goto('/inbox');
    await page.locator('[data-inbox-list] tbody tr').filter({ hasText: 'Roof audit in December' }).getByRole('link', { name: 'buyer@buyer.example.com' }).click();
    await expect(page).toHaveURL(/\/inbox\/[0-9a-f-]{36}$/);
    const item = page.url();
    const itemId = item.split('/').pop() ?? '';
    await page.goto('/projects');
    const projectId = ((await page.getByRole('link', { name: PROJECT }).first().getAttribute('href')) ?? '').split('/').pop() ?? '';
    expect(projectId).toMatch(/^[0-9a-f-]{36}$/);

    // The agent, whose token may only read the inbox and propose, proposes two tasks.
    const proposed = await page.request.post(`/api/v1/inbox/items/${itemId}/suggestions`, {
      headers: { ...worldHeader('inbox'), Authorization: `Bearer ${agentToken()}` },
      data: {
        suggestions: [{
          rationale: 'The buyer asks for a roof audit in December.',
          quote: QUOTE,
          steps: [
            { operation: 'inbox.actions.create_task', input: { project_id: projectId, title: 'Audit the warehouse roof' } },
            { operation: 'inbox.actions.create_task', input: { project_id: projectId, title: 'Order the roof materials' } },
          ],
        }],
      },
    });
    expect(proposed.status()).toBe(201);

    // The card: the steps, the source with the quote marked, and who proposed it.
    await page.goto(item);
    const card = page.locator('[data-inbox-suggestion]');
    await expect(card).toHaveCount(1);
    await expect(card.locator('[data-inbox-suggestion-rationale]')).toHaveText('The buyer asks for a roof audit in December.');
    await expect(card.locator('[data-inbox-suggestion-source]')).toContainText('buyer@buyer.example.com');
    await expect(card.locator('mark[data-inbox-suggestion-quote]')).toHaveText(QUOTE);
    await expect(card.locator('[data-inbox-suggestion-proposer]')).toContainText('Mail agent');
    await expect(card.locator('[data-inbox-suggestion-step="1"]')).toContainText('Audit the warehouse roof');
    await expect(card.locator('[data-inbox-suggestion-step="2"]')).toContainText(PROJECT);

    // The second step unticked, the card accepted: only the first task is made.
    await card.locator('[data-inbox-suggestion-step="2"] input[type=checkbox]').uncheck();
    await card.locator('[data-inbox-suggestion-accept]').click();
    await expect(page).toHaveURL(item);
    await expect(page.locator('[data-inbox-outputs]')).toContainText('Audit the warehouse roof');
    await expect(page.locator('[data-inbox-outputs]')).not.toContainText('Order the roof materials');
    const decided = page.locator('[data-inbox-suggestion][data-state="accepted"]');
    await expect(decided).toHaveCount(1);
    await expect(page.locator('[data-inbox-state]')).toHaveText('Hotové');
  });
});
