<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Domain;

use App\Purchasing\Domain\ReceivedDocumentRefused;
use PHPUnit\Framework\TestCase;

final class ReceivedDocumentRefusedTest extends TestCase
{
    public function testItNamesTheReason(): void
    {
        $refused = new ReceivedDocumentRefused(ReceivedDocumentRefused::TRASHED);

        self::assertSame('trashed', $refused->reason);
        self::assertSame('The received document refuses the change: trashed.', $refused->getMessage());
    }

    public function testAnUnknownReasonIsNoReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown reason "nope".');

        new ReceivedDocumentRefused('nope');
    }
}
