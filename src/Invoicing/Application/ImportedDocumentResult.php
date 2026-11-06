<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What became of one imported document.
 */
final readonly class ImportedDocumentResult
{
    /**
     * @param list<IssueProblem> $problems
     */
    private function __construct(
        public ImportOutcome $outcome,
        public ?string $invoiceId = null,
        public ?ImportedTotals $expected = null,
        public ?ImportedTotals $computed = null,
        public array $problems = [],
    ) {
    }

    /**
     * @param ImportedTotals $computed what Foresi priced the stored document at
     */
    public static function imported(string $invoiceId, ImportedTotals $computed): self
    {
        return new self(ImportOutcome::Imported, $invoiceId, computed: $computed);
    }

    public static function alreadyImported(string $invoiceId): self
    {
        return new self(ImportOutcome::AlreadyImported, $invoiceId);
    }

    public static function mismatch(ImportedTotals $expected, ImportedTotals $computed): self
    {
        return new self(ImportOutcome::Mismatch, expected: $expected, computed: $computed);
    }

    /**
     * @param list<IssueProblem> $problems
     */
    public static function refused(array $problems): self
    {
        return new self(ImportOutcome::Refused, problems: $problems);
    }
}
