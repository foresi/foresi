<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Foresi\Party\Infrastructure\Form\CountryOptions;
use PHPUnit\Framework\TestCase;

final class PartySubmissionReaderTest extends TestCase
{
    public function testCountryNamesFollowTheLocaleAndTheFirstTwoStayOnTop(): void
    {
        $english = CountryOptions::options('en');
        $czech = CountryOptions::options('cs');

        self::assertSame(['CZ', 'SK'], \array_slice(array_keys($english), 0, 2));
        self::assertSame('Czechia', $english['CZ']);
        self::assertSame('Česko', $czech['CZ']);
        self::assertCount(\count(CountryOptions::CODES), $english);
        self::assertTrue(CountryOptions::supports('DE'));
        self::assertFalse(CountryOptions::supports('ZZ'));
    }
}
