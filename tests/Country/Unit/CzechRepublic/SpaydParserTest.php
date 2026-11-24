<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Unit\CzechRepublic;

use Foresi\Country\Integration\Purchasing\CzechRepublic\SpaydParser;
use Foresi\Purchasing\Contract\Reading\ReadField;
use PHPUnit\Framework\TestCase;

final class SpaydParserTest extends TestCase
{
    public function testReadsAccountAmountVsAndDueDate(): void
    {
        $reading = new SpaydParser()->parse('SPD*1.0*ACC:CZ6508000000192000145399*AM:1210.50*CC:CZK*X-VS:2026100*DT:20261115');

        self::assertNotNull($reading);
        self::assertSame('CZ6508000000192000145399', $reading->get(ReadField::BankAccount)?->value);
        self::assertSame(121050, $reading->get(ReadField::Total)?->value);
        self::assertSame('CZK', $reading->get(ReadField::Currency)?->value);
        self::assertSame('2026100', $reading->get(ReadField::VariableSymbol)?->value);
        self::assertSame('2026-11-15', $reading->get(ReadField::DueDate)?->value);
        foreach ($reading->values as $value) {
            self::assertSame('payment_qr', $value->source);
        }
    }

    public function testIgnoresTextThatIsNotSpayd(): void
    {
        self::assertNull(new SpaydParser()->parse('https://example.com'));
        self::assertNull(new SpaydParser()->parse(''));
    }

    public function testRejectsAmountWithThreeDecimals(): void
    {
        $reading = new SpaydParser()->parse('SPD*1.0*ACC:CZ6508000000192000145399*AM:1210.505*CC:CZK');

        self::assertNotNull($reading);
        self::assertNull($reading->get(ReadField::Total));
        self::assertNotNull($reading->get(ReadField::BankAccount));
    }

    public function testTheCurrencyDefaultsToCzk(): void
    {
        $reading = new SpaydParser()->parse('SPD*1.0*ACC:CZ6508000000192000145399*AM:99');

        self::assertSame('CZK', $reading?->get(ReadField::Currency)?->value);
        self::assertSame(9900, $reading->get(ReadField::Total)?->value);
    }

    public function testAnAmountWithOneDecimalIsRead(): void
    {
        self::assertSame(121050, new SpaydParser()->parse('SPD*1.0*ACC:CZ6508000000192000145399*AM:1210.5')?->get(ReadField::Total)?->value);
    }

    public function testTheBicAfterTheIbanIsDropped(): void
    {
        $reading = new SpaydParser()->parse('SPD*1.0*ACC:CZ6508000000192000145399+GIBACZPX*AM:10.00');

        self::assertSame('CZ6508000000192000145399', $reading?->get(ReadField::BankAccount)?->value);
    }

    public function testUnknownKeysAreIgnoredAndBadValuesSkipped(): void
    {
        $reading = new SpaydParser()->parse('SPD*1.0*ACC:CZ6508000000192000145399*MSG:Example invoice*X-ID:7*X-VS:12ab*DT:20261340*AM:-5.00');

        self::assertNotNull($reading);
        self::assertNull($reading->get(ReadField::VariableSymbol));
        self::assertNull($reading->get(ReadField::DueDate));
        self::assertNull($reading->get(ReadField::Total));
        self::assertNotNull($reading->get(ReadField::BankAccount));
    }

    public function testASpaydWithNothingUsefulGivesNull(): void
    {
        self::assertNull(new SpaydParser()->parse('SPD*1.0*MSG:Example'));
    }
}
