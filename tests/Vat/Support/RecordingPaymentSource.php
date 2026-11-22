<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Support;

use Foresi\Vat\Contract\VatPayments;
use Foresi\Vat\Contract\VatPaymentSource;

/**
 * A payment source that remembers what it was asked and passes it on.
 */
final readonly class RecordingPaymentSource implements VatPaymentSource
{
    /**
     * @param \ArrayObject<int, array{string, string, string, ?string}> $asked
     */
    public function __construct(private VatPaymentSource $inner, private \ArrayObject $asked)
    {
    }

    public function paymentsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, string $account, ?string $variableSymbol): VatPayments
    {
        $this->asked->append([$from->format('Y-m-d'), $to->format('Y-m-d'), $account, $variableSymbol]);

        return $this->inner->paymentsBetween($from, $to, $account, $variableSymbol);
    }
}
