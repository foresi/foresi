<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Fixtures\BadNames\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

#[DomainEvent('Invoicing.Invoice.Issued')]
final readonly class Capitalised implements DomainEventPayload
{
    public function __construct(
        public string $id,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('invoice', $this->id);
    }
}
