<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\MovementInput;
use App\Banking\Application\ParsedMovement;
use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MovementDirection;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class MovementInputTest extends TestCase
{
    public function testTextsLongerThanTheirColumnsAreCutToCharactersFromTheStart(): void
    {
        $parsed = new ParsedMovement(
            MovementDirection::Incoming,
            Money::of('10.00', Currency::CZK),
            'CZ6508000000192000145399',
            'A'.str_repeat('č', BankMovement::MAX_COUNTER_ACCOUNT),
            null,
            'B'.str_repeat('ř', BankMovement::MAX_COUNTER_NAME),
            new \DateTimeImmutable('2026-11-20'),
            '20260001',
            null,
            null,
            'C'.str_repeat('ž', BankMovement::MAX_MESSAGE),
            'D'.str_repeat('š', BankMovement::MAX_BANK_REFERENCE),
        );

        $input = MovementInput::of($parsed, 'account', null);

        self::assertSame('A'.str_repeat('č', BankMovement::MAX_COUNTER_ACCOUNT - 1), $input->counterAccount);
        self::assertSame('B'.str_repeat('ř', BankMovement::MAX_COUNTER_NAME - 1), $input->counterName);
        self::assertSame('C'.str_repeat('ž', BankMovement::MAX_MESSAGE - 1), $input->message);
        self::assertSame('D'.str_repeat('š', BankMovement::MAX_BANK_REFERENCE - 1), $input->bankReference);
    }

    public function testTextsWithinTheirColumnsAndNoneStayAsTheyAre(): void
    {
        $parsed = new ParsedMovement(MovementDirection::Incoming, Money::of('10.00', Currency::CZK), 'CZ6508000000192000145399', '123456789/0800', null, 'Žlutý kůň s.r.o.', new \DateTimeImmutable('2026-11-20'), null, null, null, null, null);

        $input = MovementInput::of($parsed, 'account', null);

        self::assertSame(['123456789/0800', 'Žlutý kůň s.r.o.', null, null], [$input->counterAccount, $input->counterName, $input->message, $input->bankReference]);
    }
}
