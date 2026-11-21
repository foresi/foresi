<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One payment to or from an authority, as the cash view of a year has it
 * (Goal 4, old T7): a monthly or other advance, the settlement after the year,
 * an overpayment that comes back, or the tax of a change of the expense
 * method, due with the year of the change. It covers a period ("2026-12" for a month,
 * "2026" for the year), may be paid from $payableFrom and is due on $dueOn.
 * The amount is positive; an overpayment comes in, everything else goes out.
 *
 * A regime gives it expected. Its match rule is the authority's account and
 * variable symbol in the profile (B4): a bank movement that meets it makes the
 * payment paid, and a payment without one past its due day is overdue.
 */
final readonly class DuePayment
{
    public const string KIND_ADVANCE = 'advance';
    public const string KIND_SETTLEMENT = 'settlement';
    public const string KIND_OVERPAYMENT = 'overpayment';
    public const string KIND_SWITCH_ADJUSTMENT = 'switch_adjustment';

    public const string STATE_EXPECTED = 'expected';
    public const string STATE_PAID = 'paid';
    public const string STATE_OVERDUE = 'overdue';

    /**
     * @param string      $kind           advance, settlement, overpayment or switch_adjustment
     * @param string      $period         the month ("2026-12") or the year ("2026") it covers
     * @param string      $state          expected, paid or overdue
     * @param string|null $account        the authority's account, the match rule
     * @param string|null $variableSymbol the payment reference of the match rule
     * @param string|null $paidBy         the id of the bank movement that paid it
     */
    public function __construct(
        public Authority $authority,
        public string $kind,
        public string $period,
        public \DateTimeImmutable $payableFrom,
        public \DateTimeImmutable $dueOn,
        public Money $amount,
        public string $state = self::STATE_EXPECTED,
        public ?string $account = null,
        public ?string $variableSymbol = null,
        public ?string $paidBy = null,
        public ?\DateTimeImmutable $paidOn = null,
    ) {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException('A due payment has a positive amount; an overpayment says it comes in.');
        }
    }

    /**
     * A stable key, such as "social.advance.2026-12".
     */
    public function key(): string
    {
        return $this->authority->value.'.'.$this->kind.'.'.$this->period;
    }

    /**
     * The year whose levies it pays or returns.
     */
    public function taxYear(): int
    {
        return (int) $this->period;
    }

    /**
     * The period as a person reads it: "12/2026" for a month, "2026" for a year.
     */
    public function periodLabel(): string
    {
        return 7 === \strlen($this->period) ? substr($this->period, 5).'/'.substr($this->period, 0, 4) : $this->period;
    }

    public function comesIn(): bool
    {
        return self::KIND_OVERPAYMENT === $this->kind;
    }

    public function hasMatchRule(): bool
    {
        return null !== $this->account;
    }

    public function withMatchRule(?string $account, ?string $variableSymbol): self
    {
        return new self($this->authority, $this->kind, $this->period, $this->payableFrom, $this->dueOn, $this->amount, $this->state, $account, $variableSymbol, $this->paidBy, $this->paidOn);
    }

    public function withState(string $state, ?string $paidBy = null, ?\DateTimeImmutable $paidOn = null): self
    {
        return new self($this->authority, $this->kind, $this->period, $this->payableFrom, $this->dueOn, $this->amount, $state, $this->account, $this->variableSymbol, $paidBy, $paidOn);
    }
}
