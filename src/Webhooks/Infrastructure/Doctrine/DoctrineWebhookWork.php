<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Doctrine;

use App\Shared\Application\Event\EventOutbox;
use App\Tenancy\Application\TenantContext;
use App\Webhooks\Application\WebhookWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The unit of work of the modules on the same entity manager, as Party's
 * DoctrineTransactionalWork and the relay's DeliveryWork run it: a unit inside
 * another is part of it; on a failure the entity manager is replaced and the
 * tenant bound to it again; the outbox learns how the outermost unit ended.
 * The messages queued in it go through the Doctrine transport on the same
 * connection, so they are stored in the same transaction.
 */
final readonly class DoctrineWebhookWork implements WebhookWork
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private TenantContext $context,
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
            if ($this->context->has()) {
                $this->context->switchTo($this->context->current());
            }

            throw $failure;
        }
        $this->outbox->committed();

        return $result;
    }
}
