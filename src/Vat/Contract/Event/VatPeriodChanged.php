<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * Something a person decided about a VAT period changed: its return amount
 * was entered or cleared, it was marked settled or reopened, or a bank
 * movement was assigned to it, marked as no VAT payment or given back to the
 * automatic matching. The facts say what and which period, never an amount.
 */
#[DomainEvent('vat.period.changed', version: 1)]
final readonly class VatPeriodChanged implements DomainEventPayload
{
    public const string RETURN_AMOUNT = 'return_amount';
    public const string SETTLED = 'settled';
    public const string REOPENED = 'reopened';
    public const string PAYMENT_ASSIGNED = 'payment_assigned';
    public const string PAYMENT_AUTOMATIC = 'payment_automatic';

    /**
     * @param string      $what     return_amount, settled, reopened, payment_assigned or payment_automatic
     * @param string|null $period   the key of the period, such as "2026-11"; null for a movement that is no VAT payment
     * @param string|null $movement the id of the bank movement of a payment_assigned or payment_automatic
     */
    public function __construct(
        public string $what,
        public ?string $period,
        public ?string $movement = null,
    ) {
    }

    public function subject(): EventSubject
    {
        return null === $this->period ? new EventSubject('vat_payment', (string) $this->movement) : new EventSubject('vat_period', $this->period);
    }
}
