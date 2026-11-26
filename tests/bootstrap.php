<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// ParaTest runs the suite in parallel processes and numbers each one in TEST_TOKEN. On a
// server database every process gets a database of its own, named after the one in
// DATABASE_URL with the number appended (app_1, app_2, ...), which must already exist.
// The in-memory SQLite database is private to each process already.
$token = getenv('TEST_TOKEN');
$url = $_SERVER['DATABASE_URL'] ?? '';
// The worker processes that some tests start load this file again with the URL already
// set, so the number is appended only once.
if (false !== $token && '' !== $token && \is_string($url) && !str_starts_with($url, 'sqlite')
    && 1 !== preg_match('~^[^?]+/[^/?]+_'.preg_quote($token, '~').'(\?|$)~', $url)) {
    $url = (string) preg_replace('~^([^?]+/)([^/?]+)~', '$1$2_'.$token, $url);
    $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $url;
    putenv('DATABASE_URL='.$url);
}

if (true === (bool) ($_SERVER['APP_DEBUG'] ?? false)) {
    umask(0000);
}

// Doctrine ORM 3.8 deprecates building the schema without the DBAL schema editor API. Turning
// the editor on (doctrine.orm.use_schema_editor_api) changes what the schema tool finds to
// update, which the migration rebase relies on, so the switch waits for its own change.
Doctrine\Deprecations\Deprecation::ignoreDeprecations('https://github.com/doctrine/orm/pull/12780');
