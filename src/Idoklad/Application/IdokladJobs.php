<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Idoklad\Domain\IdokladJob;
use App\Party\Domain\LegalEntity;

/** Port: the jobs of the move from iDoklad. Call add() inside TransactionalWork, which flushes. */
interface IdokladJobs
{
    public function add(IdokladJob $job): void;

    /** Also null for a job of another tenant. */
    public function find(string $id): ?IdokladJob;

    /** The firm's latest job, ended or not. */
    public function latest(LegalEntity $firm): ?IdokladJob;

    /** The firm's job that waits or runs, if one does. */
    public function busy(LegalEntity $firm): ?IdokladJob;
}
