<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoicingDefaultsTest extends TestCase
{
    public function testStandardDefaults(): void
    {
        $defaults = InvoicingDefaults::standard();

        self::assertSame(14, $defaults->dueDays);
        self::assertSame(Currency::CZK, $defaults->currency);
        self::assertSame(DocumentLanguage::Czech, $defaults->language);
        self::assertSame(PaymentMethod::BankTransfer, $defaults->paymentMethod);
        self::assertFalse($defaults->reverseCharge);
        self::assertTrue($defaults->sendReminders, 'a contact is reminded unless the firm turns it off');
    }

    public function testTheReminderFlagTakesPartInEquality(): void
    {
        $on = $this->defaults(14);
        $off = new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::English, PaymentMethod::Cash, true, false);

        self::assertTrue($on->sendReminders);
        self::assertFalse($off->sendReminders);
        self::assertFalse($on->equals($off));
        self::assertTrue($off->equals(new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::English, PaymentMethod::Cash, true, false)));
    }

    public function testEveryValueTakesPartInEquality(): void
    {
        $base = new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true);

        self::assertTrue($base->equals(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true)));
        self::assertFalse($base->equals(new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true)));
        self::assertFalse($base->equals(new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true)));
        self::assertFalse($base->equals(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::English, PaymentMethod::BankTransfer, false, true)));
        self::assertFalse($base->equals(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::Cash, false, true)));
        self::assertFalse($base->equals(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true, true)));
        self::assertFalse($base->equals(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, false)));
    }

    public function testTheHourlyRateIsOptionalNeverNegativeAndTakesPartInEquality(): void
    {
        $base = new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true);
        $rated = new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true, Money::of('1200', Currency::EUR));

        self::assertNull($base->hourlyRate());
        self::assertEquals(Money::of('1200', Currency::EUR), $rated->hourlyRate());
        self::assertFalse($base->equals($rated));
        self::assertFalse($rated->equals(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true, Money::of('1200', Currency::USD))));
        self::assertTrue($rated->equals(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true, Money::of('1200', Currency::EUR))));

        $this->expectException(\DomainException::class);
        new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true, Money::of('-1', Currency::CZK));
    }

    #[DataProvider('validDueDays')]
    public function testAcceptsDueDaysFromZeroTo365(int $days): void
    {
        self::assertSame($days, $this->defaults($days)->dueDays);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validDueDays(): iterable
    {
        yield 'immediately' => [0];
        yield 'two weeks' => [14];
        yield 'a year' => [365];
    }

    #[DataProvider('invalidDueDays')]
    public function testRejectsDueDaysOutsideTheRange(int $days): void
    {
        $this->expectException(\DomainException::class);

        $this->defaults($days);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDueDays(): iterable
    {
        yield 'too long' => [400];
        yield 'one over' => [366];
        yield 'negative' => [-1];
    }

    private function defaults(int $days): InvoicingDefaults
    {
        return new InvoicingDefaults($days, Currency::EUR, DocumentLanguage::English, PaymentMethod::Cash, true);
    }
}
