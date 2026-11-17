<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Purchasing\Application\PaymentBalance;
use App\Purchasing\Domain\ReceivedDocument;

/**
 * A received document with what its payments add up to.
 */
final readonly class PayableDocument
{
    public function __construct(
        public ReceivedDocument $document,
        public PaymentBalance $balance,
    ) {
    }
}
