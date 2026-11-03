<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\SendMark;
use App\Invoicing\Domain\SendStatus;
use PHPUnit\Framework\TestCase;

final class SendMarkTest extends TestCase
{
    public function testTheMarkFollowsTheNewestSend(): void
    {
        self::assertSame(SendMark::None, SendMark::of(null, false));
        self::assertSame(SendMark::Queued, SendMark::of(SendStatus::Queued, false));
        self::assertSame(SendMark::Sent, SendMark::of(SendStatus::Sent, false));
        self::assertSame(SendMark::Failed, SendMark::of(SendStatus::Failed, false));
    }

    public function testACancelledSendNeverCounts(): void
    {
        self::assertSame(SendMark::None, SendMark::of(SendStatus::Cancelled, false));
    }

    public function testAnOpenedInvoiceStaysRead(): void
    {
        self::assertSame(SendMark::Read, SendMark::of(SendStatus::Failed, true));
    }
}
