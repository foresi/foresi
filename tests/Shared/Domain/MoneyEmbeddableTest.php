<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Domain;

use Doctrine\ORM\Mapping\Column;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\MoneyEmbeddable;
use PHPUnit\Framework\TestCase;

final class MoneyEmbeddableTest extends TestCase
{
    public function testRoundTripsMoney(): void
    {
        $money = Money::of('-1234.56', Currency::of('EUR'));

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
