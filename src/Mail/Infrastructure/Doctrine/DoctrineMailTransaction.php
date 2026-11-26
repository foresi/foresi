<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Doctrine;

use Foresi\Mail\Application\MailTransaction;
use Foresi\Shared\Contract\UnitOfWork;

/**
 * One transaction per change, joined when one is already open, with its events
 * handed to the outbox once it is committed and dropped when it rolls back.
 * Doctrine closes the entity manager when a transaction fails, so it is
 * replaced by a fresh one before the exception travels on.
 */
final readonly class DoctrineMailTransaction implements MailTransaction
{
    public function __construct(
        private UnitOfWork $work,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->work->run($work);
    }
}
