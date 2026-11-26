<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Activities\Application\ActivitiesTransaction;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Shared\Contract\UnitOfWork;
use Foresi\Shared\Domain\Id;

/**
 * One transaction per change, joined when one is already open, with its events
 * handed to the outbox once it is committed and dropped when it rolls back.
 */
final readonly class DoctrineActivitiesTransaction implements ActivitiesTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private UnitOfWork $work,
        private AuditCorrelation $correlation,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->correlation->within($this->correlation->current() ?? 'activities:'.Id::generate(), fn (): mixed => $this->work->run($work));
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
