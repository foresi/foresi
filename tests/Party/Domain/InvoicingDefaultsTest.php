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
