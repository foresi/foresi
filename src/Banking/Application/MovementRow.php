<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Shared\Domain\Money;

/**
 * One movement as the bank screen lists it, with the invoices its payment pays.
 */
final readonly class MovementRow
{
    /**
     * @param array<string, string> $invoices the number of each invoice its payment pays, by invoice id; none when not paired
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $accountLabel,
        public string $accountIban,
        public MovementDirection $direction,
        public Money $amount,
        public \DateTimeImmutable $bookedOn,
        public ?string $counterName,
        public ?string $counterAccount,
        public ?string $variableSymbol,
        public ?string $message,
        public MovementState $state,
        public ?UnmatchedReason $reason,
        public MovementTrust $trust,
        public ?string $inboundMessageId,
        public array $invoices,
    ) {
    }

    public function isIncoming(): bool
    {
        return MovementDirection::Incoming === $this->direction;
    }

    /**
     * Whether a person may pair it: a movement waiting for a pairing, or an outgoing
     * one recorded only before refunds paired by themselves, whose message did not
     * fail its checks. Outgoing, it may be a refund.
     */
    public function isPairable(): bool
    {
        return (MovementState::Unmatched === $this->state || (!$this->isIncoming() && MovementState::Recorded === $this->state)) && UnmatchedReason::Rejected !== $this->reason && MovementTrust::Failed !== $this->trust;
    }
}
