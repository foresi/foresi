<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Timesheet\Application;

use App\Timesheet\Application\TimeInvoicingRefused;
use PHPUnit\Framework\TestCase;

final class TimeInvoicingRefusedTest extends TestCase
{
    public function testARefusalSaysWhy(): void
    {
        $refusal = new TimeInvoicingRefused('rate_missing');

        self::assertSame('rate_missing', $refusal->reason);
        self::assertSame('The time is not invoiced: rate_missing.', $refusal->getMessage());
    }

    public function testOnlyKnownReasonsAreGiven(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TimeInvoicingRefused('whatever');
    }
}
