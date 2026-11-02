<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Tests\Invoicing\Infrastructure\Support\ReminderWorker;

/*
 * One process of the parallel reminder tests, run from the repository root with
 * DATABASE_URL set.
 *
 *   reminder-race.php seed <slots>                Prints the tenants and invoices as JSON.
 *   reminder-race.php run <ids> <job> <startAt>   Runs remind in each slot's tenant.
 *   reminder-race.php inspect <ids>               Prints the stored reminder steps as JSON.
 */
require dirname(__DIR__, 3).'/bootstrap.php';

match ($argv[1]) {
    'seed' => print json_encode(ReminderWorker::seed((int) $argv[2]), \JSON_THROW_ON_ERROR),
    'run' => print json_encode(ReminderWorker::perform($argv[2], $argv[3], (float) $argv[4]), \JSON_THROW_ON_ERROR),
    'inspect' => print json_encode(ReminderWorker::inspect($argv[2]), \JSON_THROW_ON_ERROR),
    default => throw new InvalidArgumentException('Unknown command.'),
};
