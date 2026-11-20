<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application\Import;

use Foresi\Idoklad\Domain\IdokladImportRun;

/** Port: the imports of iDoklad snapshots, checked or run. */
interface IdokladImportRuns
{
    public function add(IdokladImportRun $run): void;

    /** Also null for an unknown run. */
    public function find(string $id): ?IdokladImportRun;

    /**
     * The latest first.
     *
     * @return list<IdokladImportRun>
     */
    public function all(): array;
}
