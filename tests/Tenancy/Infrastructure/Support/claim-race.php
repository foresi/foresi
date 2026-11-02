<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Tests\Tenancy\Infrastructure\Support\ClaimWorker;

/*
 * One process of the claim race, run from the repository root with DATABASE_URL set.
 *
 *   claim-race.php seed                              Builds the schema, prints a tenant id.
 *   claim-race.php claim <tenant> <startAt> <rounds> Prints 1 or 0 per round as JSON.
 */

require dirname(__DIR__, 3).'/bootstrap.php';

match ($argv[1]) {
    'seed' => print ClaimWorker::seed(),
    'claim' => print json_encode(ClaimWorker::claim($argv[2], (float) $argv[3], (int) $argv[4]), \JSON_THROW_ON_ERROR),
    default => throw new InvalidArgumentException('Unknown command.'),
};
