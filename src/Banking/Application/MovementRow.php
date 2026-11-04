<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Shared\Domain\Money;

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
        public string $firmId,
        public string $firmName,
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
        public MessageTrust $trust,
        public ?string $inboundMessageId,
        public array $invoices,
    ) {
    }

    public function isIncoming(): bool
    {
        return MovementDirection::Incoming === $this->direction;
    }

    /**
     * Whether a person may pair it: an incoming movement waiting for a pairing whose
     * message did not fail its checks.
     */
    public function isPairable(): bool
    {
        return $this->isIncoming() && MovementState::Unmatched === $this->state && UnmatchedReason::Rejected !== $this->reason && MessageTrust::Failed !== $this->trust;
    }
}
