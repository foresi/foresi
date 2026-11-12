<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Relay;

use App\Shared\Application\Event\EventOutbox;
use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The transaction of one delivery, with the semantics of the unit of work of
 * the modules: the listener's changes, the events it records and the record of
 * its consumption are stored together or not at all. A unit of work the listener
 * opens inside it is part of it. On a failure the entity manager is replaced and
 * the tenant bound to it again, so the next message of the worker reads scoped.
 *
 * The modules' TransactionalWork lives in Party, which a core module may not
 * require; this is the same unit, on the same entity manager.
 */
final readonly class DeliveryWork
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private TenantContext $context,
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
            if ($this->context->has()) {
                $this->context->switchTo($this->context->current());
            }

            throw $failure;
        }
        $this->outbox->committed();

        return $result;
    }
}
