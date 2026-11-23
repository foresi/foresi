<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Contract;

/**
 * A document with costs as Margin reads it. The month basis is the day that
 * places the document in its month (the tax point, else the issue date); the
 * label and the url are what the person sees of it.
 */
final readonly class CostDocument
{
    /**
     * @param list<CostLine> $lines
     */
    public function __construct(
        public string $id,
        public ?string $supplierId,
        public \DateTimeImmutable $monthBasis,
        public array $lines,
        public bool $trashed,
        public string $label,
        public string $url,
    ) {
    }
}
