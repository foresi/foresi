<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Domain\IdokladImportRun;
use App\Party\Domain\LegalEntity;

/** Port: the imports of iDoklad snapshots, checked or run. */
interface IdokladImportRuns
{
    public function add(IdokladImportRun $run): void;

    /**
     * The latest first.
     *
     * @return list<IdokladImportRun>
     */
    public function forFirm(LegalEntity $firm): array;
}
