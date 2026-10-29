<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Domain\MoneyEmbeddable;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

final class MoneyEmbeddableTest extends TestCase
{
    public function testRoundTripsMoney(): void
    {
        $money = Money::of('-1234.56', Currency::EUR);

        $restored = MoneyEmbeddable::fromMoney($money)->toMoney();

        self::assertTrue($money->equals($restored));
    }

    public function testMapsMinorUnitsBigintAndCurrencyChar3(): void
    {
        $columns = [];
        foreach (new \ReflectionClass(MoneyEmbeddable::class)->getProperties() as $property) {
            foreach ($property->getAttributes(Column::class) as $attribute) {
                $column = $attribute->newInstance();
                $columns[$column->name] = [$column->type, $column->length];
            }
        }

        self::assertSame(['amount_minor' => ['bigint', null], 'currency' => ['string', 3]], $columns);
    }
}
