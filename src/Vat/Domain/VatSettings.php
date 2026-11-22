<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * Where the firm of the instance pays its VAT: the account of its tax office
 * and the variable symbol, the rule that matches the payments to the
 * periods. One row with a constant key (ADR 0039: one firm per instance), so
 * a second one cannot be stored.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vat_settings')]
class VatSettings implements Audited
{
    public const int ID = 1;
    public const int MAX_ACCOUNT = 64;
    public const int MAX_VARIABLE_SYMBOL = 35;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $id = self::ID;

    public function __construct(
        #[ORM\Column(name: 'office_account', type: 'string', length: self::MAX_ACCOUNT)]
        private string $officeAccount,
        #[ORM\Column(name: 'variable_symbol', type: 'string', length: self::MAX_VARIABLE_SYMBOL)]
        private string $variableSymbol,
    ) {
    }

    public function change(string $officeAccount, string $variableSymbol): void
    {
        $this->officeAccount = $officeAccount;
        $this->variableSymbol = $variableSymbol;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function officeAccount(): string
    {
        return $this->officeAccount;
    }

    public function variableSymbol(): string
    {
        return $this->variableSymbol;
    }
}
