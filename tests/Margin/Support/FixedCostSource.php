<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Support;

use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\MarginCostSource;

/**
 * Cost documents a test names, so Margin is tested without another module.
 */
final class FixedCostSource implements MarginCostSource
{
    /** @var array<string, CostDocument> */
    public array $documents = [];

    public function document(string $id): ?CostDocument
    {
        return $this->documents[$id] ?? null;
    }

    public function documentIds(): iterable
    {
        foreach ($this->documents as $document) {
            if (!$document->trashed) {
                yield $document->id;
            }
        }
    }

    public function documentsOf(string $supplierId, \DateTimeImmutable $fromMonth): iterable
    {
        foreach ($this->documents as $document) {
            if ($document->supplierId === $supplierId && $document->monthBasis >= $fromMonth) {
                yield $document->id;
            }
        }
    }
}
