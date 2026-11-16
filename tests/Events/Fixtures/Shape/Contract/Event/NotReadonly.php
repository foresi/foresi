<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Shape\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;

#[DomainEvent('invoicing.invoice.b')]
final class NotReadonly implements DomainEventPayload
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
