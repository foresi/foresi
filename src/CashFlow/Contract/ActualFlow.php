<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * Money that is already a fact: a document issued or received, paid or not, or
 * a bank movement booked. The forecast never counts it; it tells which planned
 * occurrences it replaces (C13, C16). The amount is positive in its own
 * currency; the direction says which way it goes.
 *
 * @param string|null $counterpartyId      the contact of a document
 * @param string|null $counterpartyAccount the other account of a bank movement, as the bank gives it
 * @param string|null $variableSymbol      the variable symbol of a bank movement
 */
final readonly class ActualFlow
{
    public function __construct(
        public string $source,
        public FlowSubject $subject,
        public FlowDirection $direction,
        public Money $amount,
        public \DateTimeImmutable $on,
        public ?string $counterpartyId = null,
        public ?string $counterpartyAccount = null,
        public ?string $variableSymbol = null,
    ) {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException('An actual flow has a positive amount; its direction says which way it goes.');
        }
    }

    /**
     * Whether this is a bank movement that meets a match rule (C16): to or
     * from the account the rule names and with the variable symbol it names,
     * each when named. A rule that names neither meets nothing, and neither
     * does a document. The amount is the caller's to compare.
     */
    public function meets(?string $account, ?string $variableSymbol): bool
    {
        if ((null === $account && null === $variableSymbol) || null !== $this->counterpartyId) {
            return false;
        }
        if (null !== $account && (null === $this->counterpartyAccount || $this->account($account) !== $this->account($this->counterpartyAccount))) {
            return false;
        }

        return null === $variableSymbol || ltrim($variableSymbol, '0') === ltrim($this->variableSymbol ?? '', '0');
    }

    /**
     * An account as it compares: without spaces and in capitals, and a Czech
     * number without the zeros a bank may pad it with ("000000-0123/0100" is "123/0100").
     */
    private function account(string $account): string
    {
        $account = strtoupper((string) preg_replace('/\s+/', '', $account));
        if (1 !== preg_match('/^(?:(\d{1,6})-)?(\d{1,10})\/(\d{4})$/', $account, $parts)) {
            return $account;
        }
        $prefix = ltrim($parts[1], '0');

        return ('' === $prefix ? '' : $prefix.'-').ltrim($parts[2], '0').'/'.$parts[3];
    }
}
