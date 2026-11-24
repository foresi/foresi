<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\MarginCostSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The modules that give Margin their cost documents, asked in turn.
 */
final readonly class CostSources
{
    /**
     * @param iterable<MarginCostSource> $sources
     */
    public function __construct(#[AutowireIterator(MarginCostSource::TAG)] private iterable $sources)
    {
    }

    public function document(string $id): ?CostDocument
    {
        foreach ($this->sources as $source) {
            $document = $source->document($id);
            if ($document instanceof CostDocument) {
                return $document;
            }
        }

        return null;
    }

    /**
     * @return iterable<string>
     */
    public function documentIds(): iterable
    {
        foreach ($this->sources as $source) {
            yield from $source->documentIds();
        }
    }

    /**
     * @return iterable<string>
     */
    public function documentIdsWithoutExchangeRate(): iterable
    {
        foreach ($this->sources as $source) {
            yield from $source->documentIdsWithoutExchangeRate();
        }
    }

    /**
     * @return iterable<string>
     */
    public function documentsOf(string $supplierId, \DateTimeImmutable $fromMonth): iterable
    {
        foreach ($this->sources as $source) {
            yield from $source->documentsOf($supplierId, $fromMonth);
        }
    }
}
