<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Fixtures\Facts\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

#[DomainEvent('invoicing.fact.array_fact')]
final readonly class ArrayFact implements DomainEventPayload
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public string $id,
        public array $value,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('fact', $this->id);
    }
}
