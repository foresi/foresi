<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application;

use Foresi\Idoklad\Domain\IdokladJob;

/** Port: the jobs of the move from iDoklad. Call add() inside TransactionalWork, which flushes. */
interface IdokladJobs
{
    public function add(IdokladJob $job): void;

    /** Also null for an unknown job. */
    public function find(string $id): ?IdokladJob;

    /** The latest job, ended or not. */
    public function latest(): ?IdokladJob;

    /** The job that waits or runs, if one does. */
    public function busy(): ?IdokladJob;
}
