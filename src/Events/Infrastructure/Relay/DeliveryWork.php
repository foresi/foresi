<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Events\Infrastructure\Relay;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Shared\Application\Event\EventOutbox;

/**
 * The transaction of one delivery, with the semantics of the unit of work of
 * the modules: the listener's changes, the events it records and the record of
 * its consumption are stored together or not at all. A unit of work the listener
 * opens inside it is part of it. On a failure the entity manager is replaced, so
 * the next message of the worker starts clean.
 *
 * The modules' TransactionalWork lives in Party, which a core module may not
 * require; this is the same unit, on the same entity manager.
 */
final readonly class DeliveryWork
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private EventOutbox $outbox,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
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
