<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

/**
 * Where a VAT period stands. A past period that is not paid is not yet
 * paid, never overdue: only the return knows the final amount.
 */
enum PeriodState: string
{
    /** The amount is what the documents give. */
    case Estimated = 'estimated';
    /** A person entered the amount of the return, which replaces the estimate. */
    case ReturnEntered = 'return_entered';
    /** A bank movement pays it, or a person marked it settled. */
    case Settled = 'settled';
    /** Its due date, or for a refund its expected date, has passed without a payment. */
    case NotYetPaid = 'not_yet_paid';

    /**
     * @param bool $settled whether a movement pays it or a person marked it
     */
    public static function of(bool $settled, bool $returnEntered, ?\DateTimeImmutable $awaitedOn, \DateTimeImmutable $today): self
    {
        return match (true) {
            $settled => self::Settled,
            $awaitedOn instanceof \DateTimeImmutable && $awaitedOn->format('Y-m-d') < $today->format('Y-m-d') => self::NotYetPaid,
            $returnEntered => self::ReturnEntered,
            default => self::Estimated,
        };
    }
}
