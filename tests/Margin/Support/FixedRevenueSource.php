<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Support;

use Foresi\Margin\Contract\MarginRevenueSource;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Contract\TemplateLine;

/**
 * Revenue documents a test names, so Margin is tested without Invoicing. It
 * answers as a real source does: the documents whose tax point is between the
 * two days.
 */
final class FixedRevenueSource implements MarginRevenueSource
{
    /** @var list<RevenueDocument> */
    public array $documents = [];

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        foreach ($this->documents as $document) {
            if ($document->taxPointOn >= $from && $document->taxPointOn <= $to) {
                yield $document;
            }
        }
    }

    /** @var array<string, TemplateLine> */
    public array $templateLines = [];

    public function document(string $id): ?RevenueDocument
    {
        foreach ($this->documents as $document) {
            if ($document->id === $id) {
                return $document;
            }
        }

        return null;
    }

    public function documentOfLine(string $invoiceLineId): ?RevenueDocument
    {
        foreach ($this->documents as $document) {
            foreach ($document->lines as $line) {
                if ($line->id === $invoiceLineId) {
                    return $document;
                }
            }
        }

        return null;
    }

    public function templateLine(string $id): ?TemplateLine
    {
        return $this->templateLines[$id] ?? null;
    }
}
