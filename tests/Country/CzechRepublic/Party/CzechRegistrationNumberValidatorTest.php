<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Party;

use App\Country\CzechRepublic\Party\CzechRegistrationNumberValidator;
use App\Party\Domain\InvalidRegistrationNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CzechRegistrationNumberValidatorTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function testValidNumbersAccepted(string $number): void
    {
        $validator = new CzechRegistrationNumberValidator();

        $validator->validate($number);

        self::assertSame($number, $validator->normalize($number));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNumbers(): iterable
    {
        // The last one has remainder 1, so its check digit is 0.
        foreach (['12345679', '12345687', '00003000'] as $number) {
            yield $number => [$number];
        }
    }

    public function testSpacesAndShortInputNormalised(): void
    {
        $validator = new CzechRegistrationNumberValidator();

        self::assertSame('12345679', $validator->normalize('1234 5679'));
        self::assertSame('00000001', $validator->normalize('1'));
        $validator->validate('00000001');
    }

    public function testBadChecksumRejected(): void
    {
        $this->expectException(InvalidRegistrationNumber::class);

        new CzechRegistrationNumberValidator()->validate('12345670');
    }

    #[DataProvider('malformedNumbers')]
    public function testNonDigitOrWrongLengthRejected(string $bad): void
    {
        $this->expectException(InvalidRegistrationNumber::class);

        new CzechRegistrationNumberValidator()->validate($bad);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedNumbers(): iterable
    {
        foreach (['1234567A', '123456791', '1234567', "12345679\n"] as $bad) {
            yield $bad => [$bad];
        }
    }

    public function testSupportsOnlyCz(): void
    {
        $validator = new CzechRegistrationNumberValidator();

        self::assertTrue($validator->supports('CZ'));
        self::assertFalse($validator->supports('SK'));
    }
}
