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
