<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Party;

use App\Country\CzechRepublic\Party\CzechBankAccount;
use App\Party\Domain\InvalidIban;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CzechBankAccount::class)]
final class CzechBankAccountTest extends TestCase
{
    public function testSupportsTheCzechRepublicOnly(): void
    {
        self::assertTrue(new CzechBankAccount()->supports('CZ'));
        self::assertFalse(new CzechBankAccount()->supports('SK'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function accounts(): iterable
    {
        yield 'number only' => ['123456789', '0100', 'CZ1801000000000123456789'];
        yield 'prefix and number' => ['19-123457', '0300', 'CZ0303000000190000123457'];
        yield 'ten digits' => ['1000000005', '5500', 'CZ4255000000001000000005'];
        yield 'white space' => [' 123 456 789 ', ' 0100 ', 'CZ1801000000000123456789'];
        yield 'shortest number' => ['12', '0100', 'CZ6801000000000000000012'];
        yield 'longest prefix' => ['123456-12', '0100', 'CZ6601001234560000000012'];
    }

    #[DataProvider('accounts')]
    public function testBuildsTheIban(string $number, string $bankCode, string $iban): void
    {
        self::assertSame($iban, new CzechBankAccount()->toIban($number, $bankCode)->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'letters' => ['12345A789', '0100'];
        yield 'one digit' => ['1', '0100'];
        yield 'eleven digits' => ['12345678901', '0100'];
        yield 'seven-digit prefix' => ['1234567-123456', '0100'];
        yield 'empty prefix' => ['-123456', '0100'];
        yield 'zeros only' => ['0000000000', '0100'];
        yield 'short bank code' => ['123456789', '100'];
        yield 'long bank code' => ['123456789', '01000'];
        yield 'letters in the bank code' => ['123456789', '01A0'];
        yield 'trailing newline' => ["123456789\n-", '0100'];
        yield 'bank code with a newline' => ['123456789', "0100\n1"];
    }

    #[DataProvider('invalid')]
    public function testRejectsWhatIsNotACzechAccount(string $number, string $bankCode): void
    {
        $this->expectException(InvalidIban::class);

        new CzechBankAccount()->toIban($number, $bankCode);
    }
}
