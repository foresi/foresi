<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Banking;

use App\Banking\Domain\MovementDirection;
use App\Country\CzechRepublic\Banking\KbLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KbLabelsTest extends TestCase
{
    #[DataProvider('writtenLabels')]
    public function testALabelComparesWithoutCaseSurroundingSpaceOrATrailingColon(string $written): void
    {
        self::assertSame(KbLabels::AMOUNT, KbLabels::field($written));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writtenLabels(): iterable
    {
        yield 'as written' => ['Částka a měna'];
        yield 'upper case' => ['ČÁSTKA A MĚNA'];
        yield 'a trailing colon' => ['Amount and currency:'];
        yield 'space before the colon' => ['Amount and currency :'];
        yield 'space after the colon' => ['Amount and currency: '];
        yield 'space around' => ['  Amount and currency  '];
    }

    public function testOtherTextIsNoLabel(): void
    {
        self::assertNull(KbLabels::field('Amount'));
        self::assertNull(KbLabels::field('Amount and currency: 150,00 CZK'));
    }

    public function testAHeadingGivesItsDirectionAndLanguage(): void
    {
        self::assertSame([MovementDirection::Incoming, KbLabels::CZECH], KbLabels::heading(' příchozí platba: '));
        self::assertSame([MovementDirection::Outgoing, KbLabels::ENGLISH], KbLabels::heading('Outgoing payment :'));
        self::assertNull(KbLabels::heading('Payment'));
    }

    public function testAStatusIsBookedNotBookedOrUnknown(): void
    {
        self::assertTrue(KbLabels::booked('Zaúčtováno'));
        self::assertTrue(KbLabels::booked('Booked :'));
        self::assertFalse(KbLabels::booked('ZAMÍTNUTO: '));
        self::assertFalse(KbLabels::booked(' rejected'));
        self::assertNull(KbLabels::booked('Odloženo'));
    }
}
