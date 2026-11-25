// SPDX-License-Identifier: AGPL-3.0-only

import { expect, test as base } from '@playwright/test';

export * from '@playwright/test';

/**
 * The test of every spec: it fails when a page, a frame or a dialog of the browser context breaks
 * the Content-Security-Policy, so a screen that a policy change would break shows up here.
 * Chromium reports each violation as a securitypolicyviolation event (CSP Level 3, "Violation
 * reports"); an init script, which runs in every frame, writes it to the console.
 */
export const test = base.extend<{ cspViolations: string[] }>({
  cspViolations: [
    async ({ context }, use) => {
      const violations: string[] = [];
      await context.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
          console.error(`CSP violation: ${event.effectiveDirective} blocked ${event.blockedURI || 'inline'} at ${event.sourceFile}:${event.lineNumber} on ${document.location.href}`);
        });
      });
      context.on('console', (message) => {
        if (message.text().startsWith('CSP violation: ')) {
          violations.push(message.text());
        }
      });
      await use(violations);
      expect(violations, 'Content-Security-Policy violations').toEqual([]);
    },
    { auto: true },
  ],
});

/**
 * page.addStyleTag for a page under the policy: the style element carries the page's nonce
 * (<meta name="csp-nonce">), as every style element of the application must.
 */
export async function addStyle(page: import('@playwright/test').Page, content: string) {
  return page.evaluateHandle((css) => {
    const style = document.createElement('style');
    style.nonce = document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.nonce ?? '';
    style.textContent = css;
    document.head.append(style);

    return style;
  }, content);
}
