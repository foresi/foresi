<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\Import;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportState;
use App\Import\Domain\Mapping;
use Psr\Clock\ClockInterface;

/**
 * Ends a run that wrote every chunk. Only now does the profile remember the
 * mapping and options, so a mapping that never worked is never proposed again.
 */
final readonly class ImportCompletion
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function finish(Import $import): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $import->moveTo($import->failed() > 0 ? ImportState::FinishedWithErrors : ImportState::Finished, $now);
        $profile = $import->profile();
        if ($profile instanceof ImportProfile) {
            $o = $import->options();
            // The typed confirmation belongs to this run only.
            $profile->remember($import->mapping(), new ImportOptions($o->mode, $o->scope, $o->matchKey, $o->blankCells, $o->dateOrder, $o->decimalSeparator, null), Mapping::signature($import->headers()), $now);
        }
    }
}
