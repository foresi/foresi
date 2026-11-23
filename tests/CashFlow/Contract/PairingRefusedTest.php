<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Contract;

use Foresi\CashFlow\Contract\PairingRefused;
use PHPUnit\Framework\TestCase;

/**
 * What a refusal of a screen that pairs carries: the reason, the message and whether the person may not at all.
 */
final class PairingRefusedTest extends TestCase
{
    public function testARefusalIsNotAForbiddenOneUnlessItSaysSo(): void
    {
        $refusal = new PairingRefused('not_an_occurrence', 'The day is no occurrence.');

        self::assertSame('not_an_occurrence', $refusal->reason);
        self::assertSame('The day is no occurrence.', $refusal->getMessage());
        self::assertFalse($refusal->forbidden);
        self::assertSame(0, $refusal->getCode());
        self::assertNull($refusal->getPrevious());
    }

    public function testAForbiddenRefusalKeepsTheCauseItWasMadeFrom(): void
    {
        $cause = new \RuntimeException('Not staff.');

        $refusal = new PairingRefused('forbidden', 'Not allowed.', true, $cause);

        self::assertTrue($refusal->forbidden);
        self::assertSame($cause, $refusal->getPrevious());
        self::assertSame(0, $refusal->getCode());
    }
}
