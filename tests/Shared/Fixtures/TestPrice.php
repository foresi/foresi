<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Fixtures;

use App\Shared\Domain\Money;
use App\Shared\Domain\MoneyEmbeddable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test-only entity that embeds a Money value, mapped in the test environment only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_price')]
class TestPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Embedded(class: MoneyEmbeddable::class, columnPrefix: false)]
    private MoneyEmbeddable $price;

    public function __construct(Money $price)
    {
        $this->price = MoneyEmbeddable::fromMoney($price);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function price(): Money
    {
        return $this->price->toMoney();
    }
}
