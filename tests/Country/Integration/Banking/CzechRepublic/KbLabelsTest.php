<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\Banking\CzechRepublic;

use Foresi\Banking\Contract\MovementDirection;
use Foresi\Country\Integration\Banking\CzechRepublic\KbLabels;
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

    public function testAHeadingGivesItsDirectionLanguageAndLayout(): void
    {
        self::assertSame(
            ['direction' => MovementDirection::Incoming, 'language' => KbLabels::CZECH, 'layout' => KbLabels::NOTICE, 'amount' => null],
            KbLabels::heading(' příchozí platba: '),
        );
        self::assertSame(
            ['direction' => MovementDirection::Outgoing, 'language' => KbLabels::ENGLISH, 'layout' => KbLabels::NOTICE, 'amount' => null],
            KbLabels::heading('Outgoing payment :'),
        );
        self::assertNull(KbLabels::heading('Payment'));
    }

    public function testAKbPlusHeadingCarriesTheAmount(): void
    {
        self::assertSame(
            ['direction' => MovementDirection::Incoming, 'language' => KbLabels::CZECH, 'layout' => KbLabels::KB_PLUS, 'amount' => '48 176,00 Kč'],
            KbLabels::heading(' PŘIJALI JSME PLATBU 48 176,00 Kč NA VÁŠ ÚČET '),
        );
        self::assertSame(
            ['direction' => MovementDirection::Incoming, 'language' => KbLabels::ENGLISH, 'layout' => KbLabels::KB_PLUS, 'amount' => '48,176.00 CZK'],
            KbLabels::heading('We have received the payment 48,176.00 CZK to your account'),
        );
        self::assertNull(KbLabels::heading('Přijali jsme platbu na Váš účet'));
        self::assertNull(KbLabels::heading('Servisní zpráva: Přijali jsme platbu 1 Kč na Váš účet'));
        self::assertNull(KbLabels::heading('We have received the payment 1 CZK to your account.'));
    }

    public function testALabelBelongsToItsLayout(): void
    {
        self::assertSame(KbLabels::OWN_ACCOUNT, KbLabels::field('Na účet', KbLabels::KB_PLUS));
        self::assertSame(KbLabels::COUNTER_ACCOUNT, KbLabels::field('From account', KbLabels::KB_PLUS));
        self::assertSame(KbLabels::DATE, KbLabels::field('Splatnost', KbLabels::KB_PLUS));
        self::assertNull(KbLabels::field('Na účet', KbLabels::NOTICE));
        self::assertNull(KbLabels::field('Částka a měna', KbLabels::KB_PLUS));
        self::assertSame(KbLabels::AMOUNT, KbLabels::field('Částka a měna', KbLabels::NOTICE));
        self::assertSame(KbLabels::OWN_ACCOUNT, KbLabels::field('To account'));
        self::assertSame(KbLabels::STATUS, KbLabels::field('Stav platby'));
    }

    public function testTheKbPlusFooterIsKnown(): void
    {
        self::assertTrue(KbLabels::footer('Chcete s něčím poradit?'));
        self::assertTrue(KbLabels::footer(' WE ARE HERE TO HELP '));
        self::assertFalse(KbLabels::footer('Call KB'));
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
