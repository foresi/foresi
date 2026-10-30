// SPDX-License-Identifier: AGPL-3.0-only

import { defineConfig } from '@playwright/test';

const port = process.env.E2E_PORT ?? '8099';
const baseURL = process.env.E2E_BASE_URL ?? `http://127.0.0.1:${port}`;

// Specs that only read data run on both viewports; the ones that change data run on the desktop only.
const READ_ONLY = /@visual|@a11y|@layout/;
const WRITES_DATA = /contact-lifecycle\.spec\.ts/;

export default defineConfig({
  testDir: './specs',
  // One database, one worker: specs that create and archive contacts must not race each other.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
  // Candidate and approved screenshots live next to the specs, one folder per viewport.
  snapshotPathTemplate: '{testDir}/../screenshots/{projectName}-{platform}/{testFilePath}/{arg}{ext}',
  expect: {
    toHaveScreenshot: { animations: 'disabled', caret: 'hide', maxDiffPixels: 20 },
  },
  use: {
    baseURL,
    locale: 'cs-CZ',
    reducedMotion: 'reduce',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    { name: 'desktop', dependencies: ['setup'], testIgnore: WRITES_DATA, use: { browserName: 'chromium', viewport: { width: 1440, height: 960 } } },
    { name: 'mobile', dependencies: ['setup'], testIgnore: WRITES_DATA, use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, hasTouch: true }, grep: READ_ONLY },
    // Must run after the read-only projects: it creates, edits and archives a contact. With one
    // worker, projects run in the order they are listed here (dependencies would skip it whenever a
    // screenshot candidate is still missing).
    { name: 'lifecycle', dependencies: ['setup'], retries: 0, testMatch: WRITES_DATA, use: { browserName: 'chromium', viewport: { width: 1440, height: 960 } } },
  ],
  // Fresh database, migrated and seeded with example.com data, served on its own port.
  webServer: {
    command: 'bash tests/e2e/serve.sh',
    cwd: '../..',
    url: `${baseURL}/login`,
    reuseExistingServer: false,
    timeout: 120_000,
    env: { E2E_PORT: port },
  },
});
