<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Facts\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;
use App\Shared\Domain\Module\ModuleKind;

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
