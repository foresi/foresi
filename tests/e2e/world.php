<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/*
 * Picks the database of the request for the end-to-end server (serve.sh prepends it to every
 * request). Each world of tests/e2e/seed.php is its own SQLite file, var/e2e/<world>.db, with one
 * organisation, as a real installation has. The browser names its world in the cookie e2e_world,
 * which signing in sets (tests/e2e/support/session.ts); an API client sends the header
 * X-E2E-World. Without either it is the world "main". An unknown world is refused, so a spec
 * never silently reads another one.
 */

$world = $_COOKIE['e2e_world'] ?? $_SERVER['HTTP_X_E2E_WORLD'] ?? 'main';
if (!is_string($world) || 1 !== preg_match('/^[a-z][a-z0-9-]{0,39}$/', $world) || !is_file(dirname(__DIR__, 2).'/var/e2e/'.$world.'.db')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unknown e2e world.';
    exit;
}
$_SERVER['E2E_WORLD'] = $_ENV['E2E_WORLD'] = $world;
putenv('E2E_WORLD='.$world);
