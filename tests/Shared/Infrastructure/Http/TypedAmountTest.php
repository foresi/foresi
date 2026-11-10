<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\TypedAmount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypedAmount::class)]
final class TypedAmountTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int|false|null}>
     */
    public static function amounts(): iterable
    {
        yield 'blank' => ['  ', 'cs', null];
        yield 'whole' => ['1500', 'cs', 150000];
        yield 'Czech' => ['1 500,50', 'cs', 150050];
        yield 'English' => ['1,500.5', 'en', 150050];
        yield 'zero' => ['0', 'cs', 0];
        yield 'negative' => ['-5', 'cs', false];
        yield 'three decimals' => ['1,505', 'cs', false];
        yield 'text' => ['a lot', 'en', false];
    }

    #[DataProvider('amounts')]
    public function testReadsAnAmountAsPeopleTypeIt(string $typed, string $locale, int|false|null $minor): void
    {
        $read = TypedAmount::read($typed, Currency::EUR, $locale);

        self::assertSame($minor, $read instanceof Money ? $read->minor() : $read);
        if ($read instanceof Money) {
            self::assertSame(Currency::EUR, $read->currency());
        }
    }

    public function testWritesAnAmountForAFieldInTheUsersLanguage(): void
    {
        self::assertSame('1500,50', TypedAmount::field(Money::ofMinor(150050, Currency::CZK), 'cs'));
        self::assertSame('1500.05', TypedAmount::field(Money::ofMinor(150005, Currency::USD), 'en'));
        self::assertSame('0,00', TypedAmount::field(Money::ofMinor(0, Currency::CZK), 'cs'));
        self::assertSame('', TypedAmount::field(null, 'cs'));
    }
}
