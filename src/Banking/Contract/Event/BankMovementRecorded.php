<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;

/**
 * A bank movement was recorded on a firm's account: which way it went, the
 * amount as a positive decimal string, the day it was booked (YYYY-MM-DD) and
 * the state it was recorded in, such as unmatched, own_transfer or
 * possible_duplicate. A later pairing records no event of its own here.
 */
#[DomainEvent('banking.movement.recorded', version: 1)]
final readonly class BankMovementRecorded implements DomainEventPayload
{
    public function __construct(
        public string $movementId,
        public string $firmId,
        public string $accountId,
        public string $direction,
        public string $amount,
        public string $currency,
        public string $bookedOn,
        public string $state,
        public ?string $variableSymbol,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('bank_movement', $this->movementId);
    }
}
