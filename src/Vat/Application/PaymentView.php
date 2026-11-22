<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\VatPayment;

/**
 * A bank movement to or from the tax office as the module answers it: the
 * period it pays, null while it is unassigned, and whether a person assigned
 * it. A refund is matched by the office account alone, so it carries the
 * unverified rule refund_account.
 */
final readonly class PaymentView
{
    public const string UNVERIFIED_REFUND_ACCOUNT = 'refund_account';

    /** @var list<string> */
    public array $unverified;

    public function __construct(
        public VatPayment $payment,
        public ?string $period,
        public bool $manual,
    ) {
        $this->unverified = VatPayment::REFUND === $payment->rule ? [self::UNVERIFIED_REFUND_ACCOUNT] : [];
    }

    /**
     * The amount in the sign of a period's result: positive paid to the
     * office, negative got back from it.
     */
    public function signed(): Money
    {
        return $this->payment->incoming ? Money::ofMinor(-$this->payment->amount->minor(), $this->payment->amount->currency()) : $this->payment->amount;
    }
}
