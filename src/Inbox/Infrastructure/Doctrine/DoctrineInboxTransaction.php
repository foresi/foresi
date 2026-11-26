<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Inbox\Application\InboxTransaction;
use Foresi\Shared\Application\Event\EventOutbox;
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
        private ManagerRegistry $registry,
        private EventOutbox $outbox,
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
            try {
                $result = $this->em->wrapInTransaction($work);
            } catch (\Throwable $failure) {
                $this->outbox->rolledBack();
                $this->registry->resetManager();

                throw $failure;
            }
            $this->outbox->committed();

            return $result;
        });
    }
}
