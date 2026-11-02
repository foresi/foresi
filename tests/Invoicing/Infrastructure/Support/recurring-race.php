<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Tests\Invoicing\Infrastructure\Support\RecurringWorker;

/*
 * One process of the parallel recurring tests, run from the repository root with
 * DATABASE_URL set.
 *
 *   recurring-race.php seed <templates>          Prints the ids as JSON.
 *   recurring-race.php run <ids> <job> <startAt> Runs issue, pause or edit, one result per template.
 *   recurring-race.php inspect <ids>             Prints what is stored as JSON.
 */
require dirname(__DIR__, 3).'/bootstrap.php';

match ($argv[1]) {
    'seed' => print json_encode(RecurringWorker::seed((int) $argv[2]), \JSON_THROW_ON_ERROR),
    'run' => print json_encode(RecurringWorker::perform($argv[2], $argv[3], (float) $argv[4]), \JSON_THROW_ON_ERROR),
    'inspect' => print json_encode(RecurringWorker::inspect($argv[2]), \JSON_THROW_ON_ERROR),
    default => throw new InvalidArgumentException('Unknown command.'),
};
