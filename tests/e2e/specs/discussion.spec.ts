// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test, type Locator, type Page } from '@playwright/test';
import { PROJECTS_OWNER_STATE } from '../support/session';

// The signed-in owner of "Example Projects" in tests/e2e/seed.php. The task "Design the homepage"
// carries an internal comment of the owner that mentions Jana Example, a public comment of hers,
// the picture homepage-sketch.png and the document brief.pdf. The tests below add to the same task.
test.use({ storageState: PROJECTS_OWNER_STATE });

// One pixel of PNG, enough for the server to sniff an image.
const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

async function openTask(page: Page): Promise<void> {
  await page.goto('/projects');
  await page.getByRole('link', { name: 'Website', exact: true }).click();
  await page.getByRole('link', { name: 'Design the homepage', exact: true }).click();
  await expect(page.locator('[data-task-details]')).toBeVisible();
}

const discussion = (page: Page) => page.locator('[data-task-discussion]');
const composer = (page: Page) => page.locator('[data-comment-composer]');
const comment = (page: Page, text: string) => page.locator('[data-comment]').filter({ hasText: text });

// What changes from one run or day to the next: times and the colour of the initials.
function volatile(block: Locator) {
  return [block.locator('time'), block.locator('[aria-hidden="true"].place-items-center')];
}

async function write(page: Page, text: string): Promise<void> {
  const body = composer(page).locator('#comment-body');
  await body.fill(text);
}

test.describe('screens', () => {
  test('the discussion and the attachments look as approved @visual', async ({ page }) => {
    await openTask(page);
    await expect(comment(page, 'First sketch is ready')).toBeVisible();
    await expect(page.locator('[data-attachment-thumbnail]')).toBeVisible();
    await expect(discussion(page)).toHaveScreenshot('discussion-content.png', { mask: volatile(discussion(page)) });
    const attachments = page.locator('[data-task-attachments]');
    await expect(attachments).toHaveScreenshot('attachments-content.png', { mask: volatile(attachments) });
  });
});

test.describe('discussion', () => {
  test('the timeline shows the history and the seeded comments, oldest first', async ({ page }) => {
    await openTask(page);
    const items = discussion(page).locator('[data-task-history] > li');
    await expect(items.first()).toHaveAttribute('data-discussion-step', '');
    const internal = comment(page, 'First sketch is ready');
    await expect(internal).toContainText('Projects Example');
    await expect(internal.locator('[data-comment-internal]')).toHaveText('Interní');
    await expect(internal.locator('strong')).toHaveText('please review');
    await expect(internal.locator('.mention')).toHaveText('@Jana Example');
    const open = comment(page, 'Looks good, the pitch reads well.');
    await expect(open).toContainText('Jana Example');
    await expect(open.locator('[data-comment-internal]')).toHaveCount(0);
    const order = await items.evaluateAll((nodes) => nodes.map((node) => node.hasAttribute('data-comment')));
    expect(order.indexOf(true)).toBeGreaterThan(0);
    const ids = await page.locator('[data-comment]').evaluateAll((nodes) => nodes.map((node) => node.textContent ?? ''));
    expect(ids.findIndex((text) => text.includes('First sketch'))).toBeLessThan(ids.findIndex((text) => text.includes('Looks good')));
  });

  test('the filter Komentáře hides the history', async ({ page }) => {
    await openTask(page);
    await expect(discussion(page).locator('[data-discussion-step]').first()).toBeVisible();
    await discussion(page).getByRole('link', { name: 'Komentáře' }).click();
    await expect(page).toHaveURL(/activity=comments/);
    await expect(discussion(page).locator('[data-discussion-step]')).toHaveCount(0);
    await expect(discussion(page).locator('[data-comment]').first()).toBeVisible();
    await discussion(page).getByRole('link', { name: 'Historie' }).click();
    await expect(discussion(page).locator('[data-comment]')).toHaveCount(0);
    await expect(discussion(page).locator('[data-discussion-step]').first()).toBeVisible();
  });

  test('an internal comment with Markdown is sent with Ctrl+Enter, rendered and shown', async ({ page }) => {
    await openTask(page);
    await write(page, 'Checked **the grid** and\n\n- the logo\n- the footer');
    await composer(page).locator('#comment-body').press('ControlOrMeta+Enter');
    await expect(page).toHaveURL(/#comment-[0-9a-f-]+$/);
    const added = comment(page, 'Checked the grid');
    await expect(added).toBeVisible();
    await expect(added.locator('strong')).toHaveText('the grid');
    await expect(added.locator('li')).toHaveCount(2);
    await expect(added.locator('[data-comment-internal]')).toHaveText('Interní');
    const id = (await added.getAttribute('data-comment')) ?? '';
    expect(page.url()).toContain('#comment-' + id);
    await expect(added).toBeInViewport();
  });

  test('a public comment is added when the switch is off', async ({ page }) => {
    await openTask(page);
    await write(page, 'Visible to guests too');
    await composer(page).getByRole('switch', { name: 'Interní komentář' }).uncheck();
    await expect(composer(page).getByText('Veřejný komentář – uvidí ho i hosté')).toBeVisible();
    await composer(page).getByRole('button', { name: 'Přidat komentář' }).click();
    const added = comment(page, 'Visible to guests too');
    await expect(added).toBeVisible();
    await expect(added.locator('[data-comment-internal]')).toHaveCount(0);
  });

  test('editing one\'s own comment marks it as edited', async ({ page }) => {
    await openTask(page);
    await write(page, 'Typo in this one');
    await composer(page).getByRole('button', { name: 'Přidat komentář' }).click();
    const own = comment(page, 'Typo in this one');
    await expect(own).toBeVisible();
    await own.locator('[data-comment-menu] button').first().click();
    await own.locator('[data-comment-edit]').click();
    const editor = page.locator('[data-comment-edit-form]');
    await editor.locator('textarea').fill('Fixed the typo');
    await editor.getByRole('button', { name: 'Uložit' }).click();
    const edited = comment(page, 'Fixed the typo');
    await expect(edited).toBeVisible();
    await expect(edited.locator('[data-comment-edited]')).toHaveText('upraveno');
  });

  test('deleting one\'s own comment leaves a placeholder', async ({ page }) => {
    await openTask(page);
    await write(page, 'To be deleted');
    await composer(page).getByRole('button', { name: 'Přidat komentář' }).click();
    const own = comment(page, 'To be deleted');
    await expect(own).toBeVisible();
    const id = (await own.getAttribute('data-comment')) ?? '';
    await own.locator('[data-comment-menu] button').first().click();
    await own.getByRole('menuitem', { name: 'Smazat' }).click();
    await page.getByRole('alertdialog').or(page.getByRole('dialog')).getByRole('button', { name: 'Smazat' }).click();
    await expect(page.locator('[data-comment="' + id + '"] [data-comment-deleted]')).toHaveText('Komentář byl smazán');
    await expect(page.getByText('To be deleted')).toHaveCount(0);
  });

  test('typing @ offers the people and Enter chooses one, which the preview shows as a chip', async ({ page }) => {
    await openTask(page);
    const body = composer(page).locator('#comment-body');
    await body.click();
    await body.pressSequentially('Thanks @Ja');
    const option = composer(page).getByRole('option', { name: /Jana Example/ });
    await expect(option).toBeVisible();
    await body.press('Enter');
    await expect(body).toHaveValue(/@\[Jana Example\]\(membership:[0-9a-f-]+\)/);
    await composer(page).getByRole('tab', { name: 'Náhled' }).click();
    await expect(composer(page).locator('[data-markdown-editor-preview] .mention')).toHaveText('@Jana Example');
  });

  test('the Preview tab renders the Markdown and removes what is not allowed', async ({ page }) => {
    await openTask(page);
    await write(page, '## Plan\n\n*soft* and `code`\n\n<script>alert(1)</script>');
    await composer(page).getByRole('tab', { name: 'Náhled' }).click();
    const preview = composer(page).locator('[data-markdown-editor-preview]');
    await expect(preview.getByRole('heading', { name: 'Plan' })).toBeVisible();
    await expect(preview.locator('em')).toHaveText('soft');
    await expect(preview.locator('code')).toHaveText('code');
    await expect(preview.locator('script')).toHaveCount(0);
    await composer(page).getByRole('tab', { name: 'Psát' }).click();
    await expect(composer(page).locator('#comment-body')).toBeVisible();
  });
});

test.describe('attachments', () => {
  test('a pasted image is uploaded and linked as Markdown', async ({ page }) => {
    await openTask(page);
    const body = composer(page).locator('#comment-body');
    await body.click();
    await body.evaluate((textarea, data) => {
      const bytes = Uint8Array.from(atob(data), (char) => char.charCodeAt(0));
      const transfer = new DataTransfer();
      transfer.items.add(new File([bytes], 'pasted.png', { type: 'image/png' }));
      textarea.dispatchEvent(new ClipboardEvent('paste', { clipboardData: transfer, bubbles: true, cancelable: true }));
    }, PIXEL);
    await expect(body).toHaveValue(/!\[pasted\.png\]\(\S+\)/);
    await expect(body).not.toHaveValue(/Nahrávám/);
  });

  test('a file chosen with the paperclip appears in Přílohy', async ({ page }) => {
    await openTask(page);
    const list = page.locator('[data-task-attachments]');
    await page.locator('#task-attachment-file').setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('Notes for the homepage.\n') });
    const row = list.locator('[data-attachment]').filter({ hasText: 'notes.txt' });
    await expect(row).toBeVisible();
    await expect(row).toHaveAttribute('data-attachment-kind', /.+/);
    await expect(row.locator('a[download]')).toHaveText('notes.txt');
  });

  test('a PDF opens in the dialog', async ({ page }) => {
    await openTask(page);
    await page.locator('[data-attachment]').filter({ hasText: 'brief.pdf' }).locator('[data-attachment-link]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('iframe')).toHaveCount(1);
    await expect(dialog).toContainText('brief.pdf');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  });

  test('an attachment is deleted after the confirmation', async ({ page }) => {
    await openTask(page);
    await page.locator('#task-attachment-file').setInputFiles({ name: 'obsolete.txt', mimeType: 'text/plain', buffer: Buffer.from('Obsolete.\n') });
    const row = page.locator('[data-attachment]').filter({ hasText: 'obsolete.txt' });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Smazat obsolete.txt' }).click();
    await page.getByRole('alertdialog').or(page.getByRole('dialog')).getByRole('button', { name: 'Smazat' }).click();
    await expect(page.locator('[data-attachment]').filter({ hasText: 'obsolete.txt' })).toHaveCount(0);
    await expect(page.locator('[data-attachment]').filter({ hasText: 'brief.pdf' })).toBeVisible();
  });
});
