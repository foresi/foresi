<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Fixtures\Facts\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;
use Foresi\Shared\Domain\Module\ModuleKind;

#[DomainEvent('invoicing.fact.foreign_enum')]
final readonly class ForeignEnumFact implements DomainEventPayload
{
    public function __construct(
        public string $id,
        public ModuleKind $value,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('fact', $this->id);
    }
}
