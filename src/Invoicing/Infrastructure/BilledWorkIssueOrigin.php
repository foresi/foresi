<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure;

use Foresi\Invoicing\Application\IssueOrigin;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Contract\BilledWorkOrigin;
use Foresi\Invoicing\Contract\BilledWorkRefused;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;

/**
 * The origin of an invoice of another module's work: that module records the
 * work by the invoice's id, kind and line ids, and its refusal aborts the
 * issue as a problem of the form. The texts of the document are its own.
 */
final readonly class BilledWorkIssueOrigin implements IssueOrigin
{
    public function __construct(private BilledWorkOrigin $origin)
    {
    }

    public static function refused(BilledWorkRefused $refusal): IssueRefused
    {
        return new IssueRefused([new IssueProblem('form', $refusal->problem)]);
    }

    public function withNumber(string $text, string $number): string
    {
        return $text;
    }

    public function recordIn(Invoice $invoice, array $lines): void
    {
        try {
            $this->origin->recordIn($invoice->id(), $invoice->kind(), array_map(static fn (InvoiceLine $line): string => $line->id(), $lines));
        } catch (BilledWorkRefused $refusal) {
            throw self::refused($refusal);
        }
    }
}
