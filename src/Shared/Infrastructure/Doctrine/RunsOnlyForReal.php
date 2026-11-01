<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

/**
 * Marks a migration that does its work in PHP while up() runs, not through
 * queued SQL. Doctrine rehearses a migration by still calling up() and skipping
 * the queued SQL, so a rehearsal of this one would change the data for real.
 */
interface RunsOnlyForReal
{
}
