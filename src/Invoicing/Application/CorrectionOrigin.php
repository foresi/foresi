<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;

/**
 * The invoice a corrective tax document corrects, as what issues it. It names the
 * invoice, the reason, the type and the decisive date in the transaction that
 * stores the document.
 */
final readonly class CorrectionOrigin implements IssueOrigin
{
    public function __construct(private CorrectionInput $correction)
    {
    }

    public function withNumber(string $text, string $number): string
    {
        return $text;
    }

    public function recordIn(Invoice $invoice, array $lines): void
    {
        CorrectionRules::record($invoice, $this->correction);
    }
}
