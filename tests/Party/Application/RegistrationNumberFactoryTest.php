<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Country\CzechRepublic\Party\CzechRegistrationNumberValidator;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\InvalidRegistrationNumber;
use PHPUnit\Framework\TestCase;

final class RegistrationNumberFactoryTest extends TestCase
{
    public function testCountryWithoutValidatorAcceptsTrimmedValue(): void
    {
        $number = new RegistrationNumberFactory([])->create('DE', ' HRB 12345 ');

        self::assertSame('HRB 12345', $number->toString());
        self::assertSame('DE', $number->countryCode);
    }

    public function testEmptyValueRejected(): void
    {
        $this->expectException(InvalidRegistrationNumber::class);

        new RegistrationNumberFactory([])->create('DE', '   ');
    }

    public function testInvalidCountryCodeRejected(): void
    {
        $this->expectException(InvalidRegistrationNumber::class);

        new RegistrationNumberFactory([])->create('Germany', '1');
    }

    public function testCountryValidatorIsUsed(): void
    {
        $factory = new RegistrationNumberFactory([new CzechRegistrationNumberValidator()]);

        self::assertSame('00000001', $factory->create('cz', '1')->value);
    }

    public function testCountryValidatorRejectsBadValue(): void
    {
        $factory = new RegistrationNumberFactory([new CzechRegistrationNumberValidator()]);

        $this->expectException(InvalidRegistrationNumber::class);

        $factory->create('CZ', '12345670');
    }
}
