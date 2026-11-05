<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\Savepoint;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A nested transaction of DBAL is a savepoint. The session is cleared after a
 * failure, so nothing the work persisted reaches the outer flush; what was there
 * before was flushed already, so clearing loses none of it.
 */
final readonly class DoctrineSavepoint implements Savepoint
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function run(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('A savepoint is part of a transaction.');
        }
        $this->em->flush();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            $connection->rollBack();
            $this->em->clear();

            throw $failure;
        }
    }
}
