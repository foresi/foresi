<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

/**
 * One proposed value and the source that read it. Dates are `Y-m-d`, money is
 * minor units in an int, `Currency` an ISO code, `VatRecap` a list of
 * `{rate, baseMinor, vatMinor}`, `BankAccount` an IBAN or `number/bank`.
 */
final readonly class ReadValue
{
    /**
     * @param string|int|array<mixed> $value
     */
    public function __construct(
        public ReadField $field,
        public string|int|array $value,
        public string $source,
    ) {
    }
}
