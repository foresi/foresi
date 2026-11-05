<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;

/**
 * The tax documents and advance invoices an invoice deducts, held for it alone.
 * In the transaction that saves the invoice, its lines claim what they deduct and
 * give up what they no longer do; a claim another invoice holds refuses the save,
 * so two invoices saved at once never deduct one document twice.
 */
final readonly class AdvanceDeductions
{
    public function __construct(private AdvanceDocuments $documents)
    {
    }

    /**
     * @param list<string> $ids what the invoice deducts, each once
     *
     * @throws IssueRefused when another invoice deducts one of them
     */
    public function claim(Invoice $invoice, array $ids): void
    {
        $invoice->markDeductions([] !== $ids);
        $this->documents->releaseDeductions($invoice->id(), $ids);
        foreach ($ids as $id) {
            if (!$this->documents->claimDeduction($id, $invoice->id())) {
                throw new IssueRefused([new IssueProblem('lines', 'invoice.problem.already_deducted')]);
            }
        }
    }

    /**
     * An invoice in the trash deducts nothing.
     */
    public function release(Invoice $invoice): void
    {
        $this->documents->releaseDeductions($invoice->id(), []);
    }

    /**
     * @param list<LineInput> $lines
     *
     * @return list<string> what the lines deduct, each once
     */
    public static function deducted(array $lines): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (LineInput $line): ?string => $line->deducts, $lines), \is_string(...))));
    }
}
