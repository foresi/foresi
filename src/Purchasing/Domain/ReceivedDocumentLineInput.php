<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Foresi\Shared\Domain\Money;

/**
 * One line of a received document as typed or read from it: what it was for,
 * its base in the document's currency and the VAT rate of the recap it falls
 * under. DocumentLines checks the lines against the recap.
 */
final readonly class ReceivedDocumentLineInput
{
    public function __construct(
        public string $text,
        public Money $base,
        public string $vatRate,
    ) {
    }
}
