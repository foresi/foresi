<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Support;

use Foresi\Margin\Contract\MarginRevenueSource;
use Foresi\Margin\Contract\RevenueDocument;

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
}
