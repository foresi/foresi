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
 *   pairing-race.php seed <invoices> [paired]  Prints the ids as JSON; with "paired" the first movements pay their invoices.
 *   pairing-race.php run <ids> <job> <startAt> Runs auto-first, auto-second, manual-first, unpair-first or cancel-first, one result per invoice.
 *   pairing-race.php inspect <ids>             Prints what is stored as JSON.
 *   pairing-race.php deadlocks                 Prints the deadlocks PostgreSQL found so far, or null.
 */
require dirname(__DIR__, 3).'/bootstrap.php';

match ($argv[1]) {
    'seed' => print json_encode(PairingWorker::seed((int) $argv[2], 'paired' === ($argv[3] ?? null)), \JSON_THROW_ON_ERROR),
    'deadlocks' => print json_encode(PairingWorker::deadlocks(), \JSON_THROW_ON_ERROR),
    'run' => print json_encode(PairingWorker::perform($argv[2], $argv[3], (float) $argv[4]), \JSON_THROW_ON_ERROR),
    'inspect' => print json_encode(PairingWorker::inspect($argv[2]), \JSON_THROW_ON_ERROR),
    default => throw new InvalidArgumentException('Unknown command.'),
};
