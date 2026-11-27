<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ReceivedDocuments\Application\Query;

use Foresi\ReceivedDocuments\Application\PaymentBalance;
use Foresi\ReceivedDocuments\Domain\DocumentReading;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentPayment;

/**
 * A received document with its payments and what remains, and the reading of its file, for the API and MCP.
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
        public ?DocumentReading $reading = null,
    ) {
    }
}
