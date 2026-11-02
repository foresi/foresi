<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\TransactionalWork;
use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine closes the entity manager when a transaction fails. A caller that
 * handles the exception (a form error, say) must be able to query on, so the
 * manager is replaced by a fresh one before the exception travels on, and the
 * active tenant is bound to it again: nothing is ever read unscoped.
 *
 * A unit of work inside another one is part of it: it runs as it is, and the
 * outermost unit flushes, commits or rolls back. Wrapping it would flush the outer
 * unit's pending changes into a savepoint and, on a failure, close and replace the
 * manager under the outer unit, which then stored nothing it held.
 */
final readonly class DoctrineTransactionalWork implements TransactionalWork
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private TenantContext $context,
    ) {
    }

    public function run(callable $work): mixed
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            return $work();
        }
        try {
            return $this->em->wrapInTransaction($work);
        } catch (\Throwable $failure) {
            $this->registry->resetManager();
            if ($this->context->has()) {
                $this->context->switchTo($this->context->current());
            }

            throw $failure;
        }
    }
}
