<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\CorrectionType;

/**
 * What a corrective tax document says of its correction, as typed. IssueRules
 * refuses what is missing.
 */
final readonly class CorrectionInput
{
    public function __construct(
        public string $correctedInvoiceId,
        public string $reason,
        public ?CorrectionType $type,
        public ?\DateTimeImmutable $decisiveDate,
    ) {
    }
}
