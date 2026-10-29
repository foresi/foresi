<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Persists Money as integer minor units plus ISO 4217 currency code.
 */
#[ORM\Embeddable]
final class MoneyEmbeddable
{
    private function __construct(
        #[ORM\Column(name: 'amount_minor', type: 'bigint')]
        private int $amountMinor,
        #[ORM\Column(name: 'currency', type: 'string', length: 3, options: ['fixed' => true])]
        private string $currency,
    ) {
    }

    public static function fromMoney(Money $money): self
    {
        return new self($money->minor(), $money->currency()->value);
    }

    public function toMoney(): Money
    {
        return Money::ofMinor($this->amountMinor, Currency::from($this->currency));
    }
}
