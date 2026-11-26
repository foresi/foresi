<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Fixtures\Facts\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;
use Foresi\Tests\Events\Fixtures\Facts\Contract\Plain;

#[DomainEvent('invoicing.fact.unit_enum')]
final readonly class UnitEnumFact implements DomainEventPayload
{
    public function __construct(
        public string $id,
        public Plain $value,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('fact', $this->id);
    }
}
