<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Activities\Application\ActivitiesTransaction;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Shared\Application\Event\EventOutbox;
use Foresi\Shared\Domain\Id;

/**
 * One transaction per change, joined when one is already open, with its events
 * handed to the outbox once it is committed and dropped when it rolls back.
 */
final readonly class DoctrineActivitiesTransaction implements ActivitiesTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private EventOutbox $outbox,
        private AuditCorrelation $correlation,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->correlation->within($this->correlation->current() ?? 'activities:'.Id::generate(), function () use ($work): mixed {
            if ($this->em->getConnection()->isTransactionActive()) {
                return $work();
            }
            try {
                $result = $this->em->wrapInTransaction($work);
            } catch (\Throwable $failure) {
                $this->outbox->rolledBack();
                $this->registry->resetManager();

                throw $failure;
            }
            $this->outbox->committed();

            return $result;
        });
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
