<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * The owner profile changed: a status was added or removed, the inputs of a
 * year were set or an authority account was set. The profile holds personal
 * data, so the facts say only what changed, never a value, a child, a birth
 * date or an amount.
 */
#[DomainEvent('taxes-and-contributions.profile.changed', version: 1)]
final readonly class ProfileChanged implements DomainEventPayload
{
    public const string STATUS = 'status';
    public const string YEAR = 'year';
    public const string AUTHORITY = 'authority';

    /**
     * @param string      $what      status, year or authority
     * @param string|null $kind      the status kind or the authority
     * @param string|null $validFrom the first day of the status
     */
    public function __construct(
        public string $what,
        public ?string $kind = null,
        public ?int $year = null,
        public ?string $validFrom = null,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('tax_profile', 'owner');
    }
}
