<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Mail\Application\MailTransaction;
use Foresi\Shared\Application\Event\EventOutbox;

/**
 * One transaction per change, joined when one is already open, with its events
 * handed to the outbox once it is committed and dropped when it rolls back.
 * Doctrine closes the entity manager when a transaction fails, so it is
 * replaced by a fresh one before the exception travels on.
 */
final readonly class DoctrineMailTransaction implements MailTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private EventOutbox $outbox,
    ) {
    }

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
