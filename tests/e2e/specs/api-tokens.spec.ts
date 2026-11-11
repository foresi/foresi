// SPDX-License-Identifier: AGPL-3.0-only

import { expect, request as playwrightRequest, test, type APIRequestContext } from '@playwright/test';
import { signIn } from '../support/session';
import { totpCode } from '../support/totp';

// The owner of "Example API" in tests/e2e/seed.php. The e2e installation does not require
// two-factor sign-in, so this owner starts without it, as a real owner would.
const API_OWNER = 'api@example.com';
const TOKEN_NAME = 'Reporting example';

// A client of the API: no cookies, only a bearer token (or none).
async function client(baseURL: string, token?: string): Promise<APIRequestContext> {
  return playwrightRequest.newContext({
    baseURL,
    extraHTTPHeaders: token === undefined ? {} : { Authorization: `Bearer ${token}` },
  });
}

test.describe('personal API tokens', () => {
  test('a token needs two-factor sign-in; once issued it reads over the API until it is revoked', async ({ page, baseURL }) => {
    await signIn(page, API_OWNER);

    // Without two-factor sign-in there is a notice and no way to issue a token.
    await page.goto('/settings/api');
    await expect(page.locator('[data-api-token-two-factor]')).toBeVisible();
    await expect(page.locator('#api-token-new')).toHaveCount(0);

    // Two-factor sign-in is set up with the key the screen shows, as an authenticator app would.
    await page.goto('/account/two-factor');
    const key = (await page.locator('[data-two-factor-key]').innerText()).trim();
    await page.locator('#setup-code').fill(totpCode(key));
    await page.getByRole('button', { name: 'Zapnout' }).click();
    await expect(page).toHaveURL(/\/account\/two-factor\/recovery-codes$/);
    await page.getByRole('link', { name: 'Pokračovat' }).click();

    // Now a token can be issued; its secret is shown once.
    await page.goto('/settings/api');
    await expect(page.locator('[data-api-token-two-factor]')).toHaveCount(0);
    await page.locator('#api-token-new').click();
    await page.locator('#name').fill(TOKEN_NAME);
    await page.getByRole('button', { name: 'Vytvořit token' }).click();
    await expect(page.locator('[data-api-token-once]')).toBeVisible();
    const secret = await page.locator('#api-token-secret').inputValue();
    expect(secret).not.toBe('');

    // The token signs in as its owner. The organisation has no documents yet.
    const api = await client(baseURL!, secret);
    const me = await api.get('/api/v1/me');
    expect(me.status()).toBe(200);
    const identity = await me.json();
    expect(identity.user.email).toBe(API_OWNER);
    expect(identity.tenant.name).toBe('Example API');
    expect(identity.token.name).toBe(TOKEN_NAME);
    expect(identity.token.scopes).toEqual(['read']);
    // The secret is never in the answer.
    expect(JSON.stringify(identity)).not.toContain(secret);

    const invoices = await api.get('/api/v1/invoices?state=unpaid&limit=5');
    expect(invoices.status()).toBe(200);
    expect(await invoices.json()).toEqual({ data: [], next_cursor: null });
    expect(invoices.headers()['ratelimit-limit']).toBe('600');
    expect(Number(invoices.headers()['ratelimit-remaining'])).toBeLessThan(600);

    // A parameter that cannot be used is a problem document that names it.
    const refused = await api.get('/api/v1/invoices?limit=1000');
    expect(refused.status()).toBe(400);
    expect(refused.headers()['content-type']).toContain('application/problem+json');
    expect(await refused.json()).toMatchObject({ code: 'invalid_parameter', parameter: 'limit' });

    // Without a token, or with one that was never issued, nobody is signed in.
    const anonymous = await client(baseURL!);
    const missing = await anonymous.get('/api/v1/me');
    expect(missing.status()).toBe(401);
    expect(await missing.json()).toMatchObject({ code: 'missing_token' });
    const forged = await client(baseURL!, 'not-a-foresi-token');
    expect((await forged.get('/api/v1/me')).status()).toBe(401);

    // The list shows the token by its last characters, never by its secret.
    await page.goto('/settings/api');
    const row = page.locator('[data-api-tokens="mine"] tbody tr').filter({ hasText: TOKEN_NAME });
    await expect(row).toContainText(`…${secret.slice(-4)}`);
    await expect(page.locator('body')).not.toContainText(secret);

    // Revoking asks first, and the token stops working at once.
    await row.getByRole('button', { name: `Další akce pro ${TOKEN_NAME}`, exact: true }).click();
    await page.getByRole('menuitem', { name: 'Zrušit token' }).click();
    const confirm = page.getByRole('alertdialog', { name: `Zrušit token ${TOKEN_NAME}?` });
    await confirm.getByRole('button', { name: 'Zrušit token' }).click();
    await expect(page.getByText('Token je zrušený.')).toBeVisible();

    const revoked = await api.get('/api/v1/me');
    expect(revoked.status()).toBe(401);
    expect(await revoked.json()).toMatchObject({ code: 'token_revoked' });

    await Promise.all([api.dispose(), anonymous.dispose(), forged.dispose()]);
  });
});
