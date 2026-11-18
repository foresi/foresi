<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * How a bank movement is recognised as an occurrence of a planned item: by the
 * counterparty's account and/or the variable symbol, with an amount within the
 * tolerance of the item's amount. It is kept now; pairing movements with it
 * comes with the bank integration.
 */
#[ORM\Embeddable]
final class MatchRule
{
    private function __construct(
        #[ORM\Column(name: 'account', type: 'string', length: PlannedItem::MAX_MATCH_ACCOUNT, nullable: true)]
        private ?string $counterpartyAccount,
        #[ORM\Column(name: 'variable_symbol', type: 'string', length: PlannedItem::MAX_VARIABLE_SYMBOL, nullable: true)]
        private ?string $variableSymbol,
        #[ORM\Column(name: 'tolerance_minor', type: 'bigint', nullable: true)]
        private ?int $toleranceMinor,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    /**
     * @param int|null $toleranceMinor how far the amount may differ, in minor units of the item's currency
     */
    public static function of(?string $counterpartyAccount, ?string $variableSymbol, ?int $toleranceMinor): self
    {
        if (null !== $toleranceMinor && $toleranceMinor < 0) {
            throw new \InvalidArgumentException('A tolerance is zero or more.');
        }

        return new self($counterpartyAccount, $variableSymbol, $toleranceMinor);
    }

    public function counterpartyAccount(): ?string
    {
        return $this->counterpartyAccount;
    }

    public function variableSymbol(): ?string
    {
        return $this->variableSymbol;
    }

    public function toleranceMinor(): ?int
    {
        return $this->toleranceMinor;
    }

    public function isEmpty(): bool
    {
        return null === $this->counterpartyAccount && null === $this->variableSymbol;
    }
}
