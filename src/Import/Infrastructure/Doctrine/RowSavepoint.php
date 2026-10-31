<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine;

use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes one row inside the transaction of its chunk, in a savepoint (DBAL 4
 * nests every inner transaction as a savepoint, on SQLite and PostgreSQL alike).
 * When the row fails, only its writes are rolled back and the chunk goes on.
 */
final readonly class RowSavepoint
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private TenantContext $context,
    ) {
    }

    /**
     * Runs the work and flushes it. On failure the savepoint is rolled back, a
     * manager closed by a failed flush is replaced and bound to the active tenant
     * again, and everything loaded is detached: the caller loads what it needs anew.
     *
     * @param callable(): void $work
     *
     * @return \Throwable|null the failure, or null when the row was written
     */
    public function attempt(callable $work): ?\Throwable
    {
        $connection = $this->em->getConnection();
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $work();
            $this->em->flush();
            $connection->commit();

            return null;
        } catch (\Throwable $failure) {
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
            if (!$this->em->isOpen()) {
                $this->registry->resetManager();
            }
            if ($this->context->has()) {
                $this->context->switchTo($this->context->current());
            } else {
                $this->em->clear();
            }

            return $failure;
        }
    }
}
