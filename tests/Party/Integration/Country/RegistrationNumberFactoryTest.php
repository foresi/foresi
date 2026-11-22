<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Country;

use Foresi\Country\CzechRepublic\Party\CzechRegistrationNumberValidator;
use Foresi\Party\Application\RegistrationNumberFactory;
use Foresi\Party\Domain\InvalidRegistrationNumber;
use PHPUnit\Framework\TestCase;

final class RegistrationNumberFactoryTest extends TestCase
{
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
