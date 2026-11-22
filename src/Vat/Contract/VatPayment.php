<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A bank movement to or from the account of the tax office, found by the
 * rule it meets: payable (out, the office account and the variable symbol),
 * refund (in, the office account only, unverified: refund_account) or other
 * (out to the office account with another variable symbol, which is never
 * matched on its own but may be assigned by a person). The amount is
 * positive, as the bank booked it; it never decides a match.
 */
final readonly class VatPayment
{
    public const string PAYABLE = 'payable';
    public const string REFUND = 'refund';
    public const string OTHER = 'other';

    /**
     * @param string $id   the subject id of the bank movement
     * @param string $rule payable, refund or other
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $on,
        public Money $amount,
        public bool $incoming,
        public string $rule,
    ) {
    }
}
