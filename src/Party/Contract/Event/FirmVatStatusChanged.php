<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A VAT status of the firm from a day on was added or removed. The facts are
 * that stretch; read the firm for the stretches that hold now. An instance
 * holds one firm (ADR 0039), so the subject names no id of its own.
 */
#[DomainEvent('party.firm.vat_status_changed', version: 1)]
final readonly class FirmVatStatusChanged implements DomainEventPayload
{
    public const string ADDED = 'added';
    public const string REMOVED = 'removed';

    /**
     * @param string      $what         added or removed
     * @param string      $status       registered, not_registered or identified_person
     * @param string|null $periodLength month or quarter, for a registered payer only
     * @param string|null $validFrom    the first day, in ISO 8601; null for the stretch that held from the start
     */
    public function __construct(
        public string $what,
        public string $status,
        public ?string $periodLength,
        public ?string $validFrom,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('firm', 'firm');
    }
}
