<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Facts\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;

#[DomainEvent('invoicing.fact.good_list')]
final readonly class GoodList implements DomainEventPayload
{
    /**
     * @param list<string> $value
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
