<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Application;

use Foresi\Party\Application\RegistrationNumberFactory;
use Foresi\Party\Domain\InvalidRegistrationNumber;
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
}
