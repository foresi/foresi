<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Contract\UnitOfWork;

/**
 * Party's unit of work is the contract's UnitOfWork (ADR 0048): a unit inside
 * another is part of it, a failure replaces the entity manager, and the outbox
 * learns how the outermost unit ended.
 */
final readonly class DoctrineTransactionalWork implements TransactionalWork
{
    public function __construct(
        private UnitOfWork $work,
        private EntityManagerInterface $em,
    ) {
    }

    public function run(callable $work): mixed
    {
        $joined = $this->em->getConnection()->isTransactionActive();
        $result = $this->work->run($work);
        if ($joined) {
            // Flushed into the open transaction, so that its caller (a step of an inbox
            // suggestion, say) can read back what it made with a query.
            $this->em->flush();
        }

        return $result;
    }
}
