<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A person chose the source of the firm's exchange rates. The facts are the
 * name of the source chosen and of the one used before, which was the
 * default when nobody had chosen yet. A document keeps the rate it was saved
 * with; only rates taken from now on come from the new source.
 */
#[DomainEvent('exchange-rate.source.changed', version: 1)]
final readonly class RateSourceChanged implements DomainEventPayload
{
    /**
     * @param string $source   the name of the source chosen, such as "ecb"
     * @param string $previous the name of the source used before, such as "cnb"
     */
    public function __construct(
        public string $source,
        public string $previous,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('exchange_rate_source', $this->source);
    }
}
