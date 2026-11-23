<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Sales\Application\SalesWork;
use Foresi\Shared\Application\Event\EventOutbox;

/**
 * The unit of work of Sales on the shared entity manager, as the catalog runs
 * its own: a unit inside another is part of it; on a failure the entity
 * manager is replaced; the outbox learns how the outermost unit ended.
 */
final readonly class DoctrineSalesWork implements SalesWork
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private EventOutbox $outbox,
    ) {
    }

    public function run(callable $work): mixed
    {
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
    }
}
