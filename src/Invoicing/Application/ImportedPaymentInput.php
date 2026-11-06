<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Money;

/**
 * A payment another system recorded on one document of the firm, as it was there.
 * The source and the id there name it for good, and the run groups what one
 * import brought, so it can be undone together.
 */
final readonly class ImportedPaymentInput
{
    /**
     * @param string $source the system it came from, such as "idoklad"
     * @param string $runId  the id of the import that brings it
     */
    public function __construct(
        public string $firmId,
        public string $invoiceId,
        public \DateTimeImmutable $receivedOn,
        public Money $amount,
        public PaymentMethod $method,
        public string $note,
        public string $source,
        public string $externalId,
        public string $runId,
    ) {
    }
}
