<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Margin\Contract\MarginRevenueSource;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Contract\TemplateLine;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The modules that give Margin their revenue documents, asked in turn.
 */
final readonly class RevenueSources
{
    /**
     * @param iterable<MarginRevenueSource> $sources
     */
    public function __construct(#[AutowireIterator(MarginRevenueSource::TAG)] private iterable $sources)
    {
    }

    /**
     * @return iterable<RevenueDocument>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        foreach ($this->sources as $source) {
            yield from $source->between($from, $to);
        }
    }

    public function document(string $id): ?RevenueDocument
    {
        foreach ($this->sources as $source) {
            $document = $source->document($id);
            if ($document instanceof RevenueDocument) {
                return $document;
            }
        }

        return null;
    }

    public function documentOfLine(string $invoiceLineId): ?RevenueDocument
    {
        foreach ($this->sources as $source) {
            $document = $source->documentOfLine($invoiceLineId);
            if ($document instanceof RevenueDocument) {
                return $document;
            }
        }

        return null;
    }

    public function templateLine(string $id): ?TemplateLine
    {
        foreach ($this->sources as $source) {
            $line = $source->templateLine($id);
            if ($line instanceof TemplateLine) {
                return $line;
            }
        }

        return null;
    }
}
