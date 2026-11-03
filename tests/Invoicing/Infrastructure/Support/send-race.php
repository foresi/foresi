<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Tests\Invoicing\Infrastructure\Support\SendWorker;

/*
 * One process of the parallel sending tests, run from the repository root with
 * DATABASE_URL set.
 *
 *   send-race.php seed <invoices> <queued>   Prints the ids as JSON; queued 1 adds a send to each.
 *   send-race.php run <ids> <job> <startAt>  Runs deliver, trash or send, one result per invoice.
 *   send-race.php inspect <ids>              Prints what is stored as JSON.
 */
require dirname(__DIR__, 3).'/bootstrap.php';

match ($argv[1]) {
    'seed' => print json_encode(SendWorker::seed((int) $argv[2], '1' === $argv[3]), \JSON_THROW_ON_ERROR),
    'run' => print json_encode(SendWorker::perform($argv[2], $argv[3], (float) $argv[4]), \JSON_THROW_ON_ERROR),
    'inspect' => print json_encode(SendWorker::inspect($argv[2]), \JSON_THROW_ON_ERROR),
    default => throw new InvalidArgumentException('Unknown command.'),
};
