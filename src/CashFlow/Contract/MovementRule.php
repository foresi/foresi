<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * A rule a bank movement may meet: the counterparty account and the variable
 * symbol it names, each when named, and the direction when given. A rule that
 * names neither an account nor a variable symbol meets nothing.
 *
 * @param string $key the caller's name for the rule, given back on each movement that meets it
 */
final readonly class MovementRule
{
    public function __construct(
        public string $key,
        public ?string $account,
        public ?string $variableSymbol,
        public ?FlowDirection $direction = null,
    ) {
    }

    public function isMetBy(ActualFlow $actual): bool
    {
        return (!$this->direction instanceof FlowDirection || $this->direction === $actual->direction) && $actual->meets($this->account, $this->variableSymbol);
    }
}
