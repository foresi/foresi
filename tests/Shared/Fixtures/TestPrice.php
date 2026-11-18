<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\MoneyEmbeddable;

/**
 * Test-only entity that embeds a Money value, mapped in the test environment only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_price')]
class TestPrice
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Embedded(class: MoneyEmbeddable::class, columnPrefix: false)]
    private MoneyEmbeddable $price;

    public function __construct(Money $price)
    {
        $this->id = Id::generate();
        $this->price = MoneyEmbeddable::fromMoney($price);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function price(): Money
    {
        return $this->price->toMoney();
    }
}
