<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MovementDirection;
use App\Shared\Domain\Money;

/**
 * A movement to record on an account of a firm, as a bank message stated it. Text
 * a bank may write long is cut to what is stored; what pairs (amounts, symbols,
 * accounts) is never cut, and a symbol too long for the column is refused.
 */
final readonly class MovementInput
{
    /**
     * @param string      $accountId        the firm's bank account it was booked on
     * @param string|null $inboundMessageId the message it came in, or null when it came another way
     */
    public function __construct(
        public string $accountId,
        public ?string $inboundMessageId,
        public MovementDirection $direction,
        public Money $amount,
        public \DateTimeImmutable $bookedOn,
        public ?string $counterAccount,
        public ?string $counterIban,
        public ?string $counterName,
        public ?string $variableSymbol,
        public ?string $constantSymbol,
        public ?string $specificSymbol,
        public ?string $message,
        public ?string $bankReference,
    ) {
    }

    public static function of(ParsedMovement $movement, string $accountId, ?string $inboundMessageId): self
    {
        return new self(
            $accountId,
            $inboundMessageId,
            $movement->direction,
            $movement->amount,
            $movement->bookedOn,
            self::cut($movement->counterAccount, BankMovement::MAX_COUNTER_ACCOUNT),
            $movement->counterIban,
            self::cut($movement->counterName, BankMovement::MAX_COUNTER_NAME),
            $movement->variableSymbol,
            $movement->constantSymbol,
            $movement->specificSymbol,
            self::cut($movement->message, BankMovement::MAX_MESSAGE),
            self::cut($movement->bankReference, BankMovement::MAX_BANK_REFERENCE),
        );
    }

    /**
     * The same for the same movement told twice, whatever message brought it. The
     * account is in it, so equal movements on two accounts differ.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->accountId,
            $this->direction->value,
            $this->amount->minor(),
            $this->amount->currency()->value,
            $this->bookedOn->format('Y-m-d'),
            $this->counterAccount,
            $this->counterIban,
            $this->counterName,
            $this->variableSymbol,
            $this->constantSymbol,
            $this->specificSymbol,
            $this->message,
            $this->bankReference,
        ], \JSON_THROW_ON_ERROR));
    }

    private static function cut(?string $text, int $max): ?string
    {
        return null === $text ? null : mb_substr($text, 0, $max);
    }
}
