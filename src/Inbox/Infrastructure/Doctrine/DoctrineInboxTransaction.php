<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Inbox\Application\InboxTransaction;
use Foresi\Shared\Contract\UnitOfWork;
use Foresi\Shared\Domain\Id;

/**
 * One transaction per change, joined and flushed into when one is already
 * open, with its events handed to the outbox once it is committed and dropped
 * when it rolls back. A joined transaction leaves both to its owner.
 */
final readonly class DoctrineInboxTransaction implements InboxTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private UnitOfWork $work,
        private AuditCorrelation $correlation,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->correlation->within($this->correlation->current() ?? 'inbox:'.Id::generate(), function () use ($work): mixed {
            if ($this->em->getConnection()->isTransactionActive()) {
                $result = $work();
                $this->em->flush();

                return $result;
            }

            return $this->work->run($work);
        });
    }
}
