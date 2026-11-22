<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Country;

use Foresi\Country\CzechRepublic\Party\CzechBankAccount;
use Foresi\Party\Application\AccountNumbers;
use PHPUnit\Framework\TestCase;

/**
 * A bank account as people write it, read as an IBAN, and the refusal that
 * names every wrong field of a contact part.
 */
final class AccountNumbersTest extends TestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    public function testADomesticNumberIsReadWithSpacesAroundIt(): void
    {
        self::assertSame(self::IBAN, $this->numbers()->toIban(' 19-2000145399/0800 ', 'CZ')?->toString());
        self::assertSame(self::IBAN, $this->numbers()->toIban('19-2000145399/ 0800', 'CZ')?->toString());
    }

    public function testAnIbanIsReadWhateverTheCountry(): void
    {
        self::assertSame(self::IBAN, $this->numbers()->toIban(self::IBAN, 'DE')?->toString());
    }

    public function testWithNoCountryAnyCountryModuleReadsTheNumber(): void
    {
        self::assertSame(self::IBAN, $this->numbers()->toIban('19-2000145399/0800', '')?->toString());
    }

    public function testADomesticNumberOfAnotherCountryIsNotRead(): void
    {
        self::assertNull($this->numbers()->toIban('19-2000145399/0800', 'DE'));
    }

    public function testANumberNoModuleTakesIsNotRead(): void
    {
        self::assertNull($this->numbers()->toIban('1/0800', 'CZ'));
        self::assertNull($this->numbers()->toIban('not a number', 'CZ'));
    }

    private function numbers(): AccountNumbers
    {
        return new AccountNumbers([new CzechBankAccount()]);
    }
}
