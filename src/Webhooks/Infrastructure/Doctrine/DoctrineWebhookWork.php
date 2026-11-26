<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Doctrine;

use Foresi\Shared\Contract\UnitOfWork;
use Foresi\Webhooks\Application\WebhookWork;

/**
 * The unit of work of the modules on the same entity manager, as Party's
 * DoctrineTransactionalWork and the relay's DeliveryWork run it: a unit inside
 * another is part of it; on a failure the entity manager is replaced; the outbox learns how the outermost unit ended.
 * The messages queued in it go through the Doctrine transport on the same
 * connection, so they are stored in the same transaction.
 */
final readonly class DoctrineWebhookWork implements WebhookWork
{
    public function __construct(
        private UnitOfWork $work,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->work->run($work);
    }
}
