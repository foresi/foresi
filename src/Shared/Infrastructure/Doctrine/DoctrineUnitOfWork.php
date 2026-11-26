<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Shared\Application\Event\EventOutbox;
use Foresi\Shared\Contract\UnitOfWork;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The unit of work on the shared entity manager, for third-party modules and
 * behind every core module's own unit.
 *
 * Doctrine closes the entity manager when a transaction fails. A caller that
 * handles the exception (a form error, say) must be able to query on, so the
 * manager is replaced by a fresh one before the exception travels on.
 *
 * A unit inside another one is part of it: it runs inside the outer transaction
 * and flushes into it, so that its caller can read back what it made with a query
 * (an answer that finds the new record by its id, say, in a step of an inbox
 * suggestion); the outermost unit commits or rolls back. Wrapping it would flush the outer unit's
 * pending changes into a savepoint and, on a failure, close and replace the
 * manager under the outer unit, which then stored nothing it held.
 *
 * The outbox is told how the outermost unit ended: events recorded in it are
 * released after the commit and dropped after a rollback.
 */
#[AsAlias(UnitOfWork::class)]
final readonly class DoctrineUnitOfWork implements UnitOfWork
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
            $result = $work();
            $this->em->flush();

            return $result;
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
