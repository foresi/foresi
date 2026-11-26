<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Contract;

use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

/**
 * A contact as other modules read it: invoices to it are due in the standard
 * 14 days unless it says otherwise.
 */
final class PartySummaryTest extends TestCase
{
    public function testAnInvoiceIsDueInFourteenDaysUnlessTheContactSaysOtherwise(): void
    {
        $summary = new PartySummary(Id::generate(), ContactKind::Company, 'Client Example s.r.o.', null, null, [], null, null, ['customer'], false);

        self::assertSame(14, $summary->dueDays);
    }
}
