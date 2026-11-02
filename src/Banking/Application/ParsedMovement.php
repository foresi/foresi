<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\MovementDirection;
use App\Shared\Domain\Money;

/**
 * One movement on the firm's account as a bank message states it. The amount is
 * always positive; the direction says which way it went. The counter-account is
 * kept as written, in whatever national form the bank used, beside the IBAN
 * when the bank gave one or it could be derived.
 */
final readonly class ParsedMovement
{
    /**
     * @param string      $ownAccount    IBAN of the firm's account the movement was booked on
     * @param string|null $bankReference the bank's own identifier of the movement, when it gives one
     */
    public function __construct(
        public MovementDirection $direction,
        public Money $amount,
        public string $ownAccount,
        public ?string $counterAccount,
        public ?string $counterIban,
        public ?string $counterName,
        public \DateTimeImmutable $bookedOn,
        public ?string $variableSymbol,
        public ?string $constantSymbol,
        public ?string $specificSymbol,
        public ?string $message,
        public ?string $bankReference,
    ) {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new \InvalidArgumentException('A movement amount is positive; the direction carries the sign.');
        }
    }
}
