<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\SendRefused;
use PHPUnit\Framework\TestCase;

final class SendRefusedTest extends TestCase
{
    public function testARefusedSendNamesItsReason(): void
    {
        $refused = new SendRefused(SendRefused::BAD_ADDRESS, ['nobody@']);

        self::assertSame('The send was refused: bad_address.', $refused->getMessage());
        self::assertSame(['nobody@'], $refused->details);
    }
}
