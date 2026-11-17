<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Purchasing\Application\PaymentBalance;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentPayment;

/**
 * A received document with its payments and what remains, for the API and MCP.
 */
final readonly class PaidDocument
{
    /**
     * @param list<ReceivedDocumentPayment> $payments by the day paid
     */
    public function __construct(
        public ReceivedDocument $document,
        public PaymentBalance $balance,
        public array $payments,
    ) {
    }
}
