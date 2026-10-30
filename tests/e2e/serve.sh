#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Starts the application for the end-to-end tests: a fresh SQLite database
# (var/e2e.db), migrated and seeded, served by the PHP built-in server. It never
# touches the development database. Run from the repository root by Playwright.
set -euo pipefail

port="${E2E_PORT:-8099}"

export APP_ENV=e2e
export APP_DEBUG=1

# Fresh state: the login throttle and the registry cache live in the file cache.
rm -rf var/cache/e2e
rm -f var/e2e.db
php bin/console doctrine:migrations:migrate --no-interaction --quiet
php tests/e2e/seed.php

export PHP_CLI_SERVER_WORKERS=4
# variables_order=EGPCS: the built-in server otherwise hides APP_ENV from Symfony, which then
# falls back to the dev environment and the developer database.
exec php -d variables_order=EGPCS -S "127.0.0.1:${port}" -t public public/index.php
