<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\Purchasing\CzechRepublic;

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

    public function testAnInvalidCurrencyGivesNeitherCurrencyNorTotal(): void
    {
        $reading = new SpaydParser()->parse('SPD*1.0*ACC:CZ6508000000192000145399*AM:10.00*CC:CZ');

        self::assertNotNull($reading);
        self::assertNull($reading->get(ReadField::Currency));
        self::assertNull($reading->get(ReadField::Total));
        self::assertNotNull($reading->get(ReadField::BankAccount));
    }

    public function testAZeroAmountGivesNoTotal(): void
    {
        $reading = new SpaydParser()->parse('SPD*1.0*ACC:CZ6508000000192000145399*AM:0.00');

        self::assertNotNull($reading);
        self::assertNull($reading->get(ReadField::Total));
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $reading = new SpaydParser()->parse(" \nSPD*1.0*ACC:CZ6508000000192000145399*AM:10.00\n");

        self::assertSame(1000, $reading?->get(ReadField::Total)?->value);
    }

    public function testOnlyAPaymentDescriptorIsRead(): void
    {
        self::assertNull(new SpaydParser()->parse('XYZ*1.0*ACC:CZ6508000000192000145399*AM:10.00'));
    }

    public function testKeysAreCaseInsensitiveAndTheFirstOfAKeyWins(): void
    {
        $reading = new SpaydParser()->parse('SPD*1.0*acc:cz6508000000192000145399*am:1.00*Am:2.00*cc:czk*x-vs:7*dt:20261115');

        self::assertSame('CZ6508000000192000145399', $reading?->get(ReadField::BankAccount)?->value);
        self::assertSame(100, $reading->get(ReadField::Total)?->value);
        self::assertSame('CZK', $reading->get(ReadField::Currency)?->value);
        self::assertSame('7', $reading->get(ReadField::VariableSymbol)?->value);
        self::assertSame('2026-11-15', $reading->get(ReadField::DueDate)?->value);
    }

    public function testTheAccountIsAWholeIban(): void
    {
        $parser = new SpaydParser();

        self::assertNull($parser->parse('SPD*1.0*ACC:CZ65'.str_repeat('0', 31).'*AM:10.00')?->get(ReadField::BankAccount));
        self::assertNull($parser->parse('SPD*1.0*ACC:1CZ6508000000192000145399*AM:10.00')?->get(ReadField::BankAccount));
        self::assertSame('CZ65'.str_repeat('0', 30), $parser->parse('SPD*1.0*ACC:CZ65'.str_repeat('0', 30).'*AM:10.00')?->get(ReadField::BankAccount)?->value);
    }

    public function testTheCurrencyIsThreeLettersAndNothingElse(): void
    {
        $parser = new SpaydParser();

        foreach (['XCZK', 'CZKX', '1CZ'] as $code) {
            $reading = $parser->parse('SPD*1.0*ACC:CZ6508000000192000145399*AM:10.00*CC:'.$code);

            self::assertNull($reading?->get(ReadField::Currency), $code);
            self::assertInstanceOf(\Foresi\Purchasing\Contract\Reading\Reading::class, $reading);
            self::assertNull($reading->get(ReadField::Total), $code);
        }
    }

    public function testTheVariableSymbolAndTheDueDateAreWholeValues(): void
    {
        $parser = new SpaydParser();
        $base = 'SPD*1.0*ACC:CZ6508000000192000145399*AM:10.00*';

        self::assertNull($parser->parse($base.'X-VS:a123')?->get(ReadField::VariableSymbol));
        self::assertNull($parser->parse($base.'X-VS:123a')?->get(ReadField::VariableSymbol));
        self::assertNull($parser->parse($base.'X-VS:12345678901')?->get(ReadField::VariableSymbol));
        self::assertSame('1234567890', $parser->parse($base.'X-VS:1234567890')?->get(ReadField::VariableSymbol)?->value);
        self::assertNull($parser->parse($base.'DT:x20261115')?->get(ReadField::DueDate));
        self::assertNull($parser->parse($base.'DT:20261115x')?->get(ReadField::DueDate));
    }
}
