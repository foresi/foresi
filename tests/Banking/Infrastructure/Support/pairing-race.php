<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Tests\Banking\Infrastructure\Support\PairingWorker;

/*
 * One process of the parallel pairing tests, run from the repository root with
 * DATABASE_URL set.
 *
 *   pairing-race.php seed <invoices>          Prints the ids as JSON.
 *   pairing-race.php run <ids> <job> <startAt> Runs auto-first, auto-second or manual-first, one result per invoice.
 *   pairing-race.php inspect <ids>             Prints what is stored as JSON.
 */
require dirname(__DIR__, 3).'/bootstrap.php';

match ($argv[1]) {
    'seed' => print json_encode(PairingWorker::seed((int) $argv[2]), \JSON_THROW_ON_ERROR),
    'run' => print json_encode(PairingWorker::perform($argv[2], $argv[3], (float) $argv[4]), \JSON_THROW_ON_ERROR),
    'inspect' => print json_encode(PairingWorker::inspect($argv[2]), \JSON_THROW_ON_ERROR),
    default => throw new InvalidArgumentException('Unknown command.'),
};
