<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Tests\Invoicing\Infrastructure\Support\InvoiceWorker;

/*
 * One process of the parallel invoice tests, run from the repository root with
 * DATABASE_URL set.
 *
 *   race.php seed <invoices> <lines>      Prints the ids as JSON.
 *   race.php run <ids> <job> <startAt>    Runs the job, prints one result per invoice.
 *   race.php inspect <ids>                Prints what is stored as JSON.
 */

require dirname(__DIR__, 3).'/bootstrap.php';

match ($argv[1]) {
    'seed' => print json_encode(InvoiceWorker::seed((int) $argv[2], (int) $argv[3]), \JSON_THROW_ON_ERROR),
    'run' => print json_encode(InvoiceWorker::perform($argv[2], $argv[3], (float) $argv[4]), \JSON_THROW_ON_ERROR),
    'inspect' => print json_encode(InvoiceWorker::inspect($argv[2]), \JSON_THROW_ON_ERROR),
    default => throw new InvalidArgumentException('Unknown command.'),
};
