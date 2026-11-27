<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\Banking\CzechRepublic;

use Foresi\Country\Integration\Banking\CzechRepublic\CzechBanking;
use PHPUnit\Framework\TestCase;

final class CzechBankingTest extends TestCase
{
    public function testACzechIbanIsWrittenAsItsNumberAndBankCode(): void
    {
        $banking = new CzechBanking();

        self::assertTrue($banking->supports('CZ'));
        self::assertFalse($banking->supports('SK'));
        self::assertSame('19-2000145399/0800', $banking->domesticAccount('CZ6508000000192000145399'));
        self::assertNull($banking->domesticAccount('SK3112000000198742637541'), 'a foreign IBAN stays an IBAN');
        self::assertSame('Europe/Prague', $banking->timezone()->getName());
    }
}
