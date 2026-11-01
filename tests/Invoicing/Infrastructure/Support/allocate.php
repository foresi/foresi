<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Tests\Invoicing\Infrastructure\Support\CounterWorker;

/*
 * One process of the parallel counter test, run from the repository root with
 * DATABASE_URL set.
 *
 *   allocate.php seed
 *       Prints the series and its tenant.
 *   allocate.php <series> <tenant> <count> <startAt> <rollBackEvery>
 *       Prints the numbers that stayed, then the times it began and ended.
 */

require dirname(__DIR__, 3).'/bootstrap.php';

if ('seed' === $argv[1]) {
    echo implode(' ', CounterWorker::seed())."\n";

    exit(0);
}

[$numbers, $began, $ended] = CounterWorker::allocate($argv[1], $argv[2], (int) $argv[3], (float) $argv[4], (int) $argv[5]);

echo implode("\n", $numbers)."\n";
printf("%.6F %.6F\n", $began, $ended);
