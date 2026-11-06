<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Doctrine;

use App\Idoklad\Application\Import\ImportTransaction;
use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The units of work inside see the open transaction and leave the flush,
 * commit and rollback to it (see DoctrineTransactionalWork). A rolled-back
 * transaction leaves entities the manager still holds for stored, so it
 * forgets them; a failed one may have closed the manager, which is replaced
 * and bound to the active tenant again.
 */
final readonly class DoctrineImportTransaction implements ImportTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private TenantContext $context,
    ) {
    }

    public function run(callable $work, bool $commit): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->em->flush();
        } catch (\Throwable $failure) {
            $connection->rollBack();
            $this->registry->resetManager();
            $this->context->switchTo($this->context->current());

            throw $failure;
        }
        if ($commit) {
            $connection->commit();
        } else {
            $connection->rollBack();
            $this->em->clear();
        }

        return $result;
    }
}
