<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\SendLogFilter;
use PHPUnit\Framework\TestCase;

final class SendLogFilterTest extends TestCase
{
    public function testTheSendLogStartsOnTheFirstPageOfEverything(): void
    {
        $filter = new SendLogFilter();

        self::assertSame(1, $filter->page);
        self::assertNull($filter->firmId);
        self::assertNull($filter->status);
    }
}
