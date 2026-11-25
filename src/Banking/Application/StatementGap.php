<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\StatementGapKind;
use Foresi\Banking\Domain\ReceivedStatement;
use Foresi\Shared\Domain\Money;

/**
 * Where the statements of an account do not follow on: before a statement, after
 * the one before it, or inside a statement that does not add up.
 */
final readonly class StatementGap
{
    /**
     * @param Money|null $expected the balance that was due: the previous closing one, or the opening one with the entries
     * @param Money|null $actual   the balance the statement states
     */
    public function __construct(
        public StatementGapKind $kind,
        public ReceivedStatement $statement,
        public ?ReceivedStatement $previous,
        public ?Money $expected = null,
        public ?Money $actual = null,
    ) {
    }

    /**
     * The same gap found again has the same key.
     */
    public function key(): string
    {
        return $this->kind->value.':'.($this->previous?->id() ?? '').':'.$this->statement->id();
    }
}
