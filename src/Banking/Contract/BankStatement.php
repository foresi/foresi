<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * What a bank sent at once about one account of the firm: a statement file, an
 * answer of its API, or a notification mail. Everything but the account, the
 * currency and the entries is optional, as a mail or a plain export carries none of
 * it; what is given is checked: the period runs forward, and the balances and the
 * entries are in the statement's currency.
 *
 * The balances are signed: an overdrawn account opens below zero. The entries are
 * the movements as the bank stated them; a bank reference, when given, is the key
 * that makes a second import of the same entry known.
 */
final readonly class BankStatement
{
    public const int MAX_STATEMENT_ID = 64;

    /**
     * @param string               $ownAccount  IBAN of the firm's account
     * @param list<ParsedMovement> $entries     the movements, in the order the bank listed them
     * @param string|null          $statementId the bank's own id of the statement (MT940 :20:, camt.053 Stmt/Id)
     */
    public function __construct(
        public string $ownAccount,
        public Currency $currency,
        public array $entries,
        public ?string $statementId = null,
        public ?StatementSequence $sequence = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?Money $openingBalance = null,
        public ?Money $closingBalance = null,
    ) {
        $account = $this->compact($ownAccount);
        if ('' === $account) {
            throw new \InvalidArgumentException('A statement names the account it is of.');
        }
        foreach ($entries as $entry) {
            if ($this->compact($entry->ownAccount) !== $account) {
                throw new \InvalidArgumentException('Every entry of a statement is booked on the statement\'s account.');
            }
        }
        if (null !== $statementId && ('' === trim($statementId) || mb_strlen($statementId) > self::MAX_STATEMENT_ID)) {
            throw new \InvalidArgumentException(\sprintf('A statement id is not empty and at most %d characters.', self::MAX_STATEMENT_ID));
        }
        if ($from instanceof \DateTimeImmutable && $to instanceof \DateTimeImmutable && $from > $to) {
            throw new \InvalidArgumentException('A statement period ends after it starts.');
        }
        if ($openingBalance instanceof Money || $closingBalance instanceof Money) {
            foreach ([$openingBalance, $closingBalance] as $balance) {
                if ($balance instanceof Money && $balance->currency() !== $currency) {
                    throw new \InvalidArgumentException('The balances of a statement are in its currency.');
                }
            }
            foreach ($entries as $entry) {
                if ($entry->amount->currency() !== $currency) {
                    throw new \InvalidArgumentException('The entries of a statement with balances are in its currency.');
                }
            }
        }
    }

    /**
     * The entries' sum, incoming money positive and outgoing negative.
     */
    public function net(): Money
    {
        $net = Money::ofMinor(0, $this->currency);
        foreach ($this->entries as $entry) {
            if ($entry->amount->currency() !== $this->currency) {
                continue;
            }
            $net = MovementDirection::Incoming === $entry->direction ? $net->plus($entry->amount) : $net->minus($entry->amount);
        }

        return $net;
    }

    /**
     * Whether the statement carries anything a gap is told by: a number or a balance.
     */
    public function isChained(): bool
    {
        return $this->sequence instanceof StatementSequence || $this->openingBalance instanceof Money || $this->closingBalance instanceof Money;
    }

    private function compact(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $iban));
    }
}
