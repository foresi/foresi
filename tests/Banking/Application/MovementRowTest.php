<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\MovementFilter;
use App\Banking\Application\MovementRow;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A row of the bank screen, and the filter that asks for the rows.
 */
final class MovementRowTest extends TestCase
{
    /**
     * @return iterable<string, array{MovementDirection, MovementState, ?UnmatchedReason, MessageTrust, bool}>
     */
    public static function rows(): iterable
    {
        yield 'an incoming one waiting' => [MovementDirection::Incoming, MovementState::Unmatched, UnmatchedReason::NoInvoice, MessageTrust::Verified, true];
        yield 'an unverified one waiting' => [MovementDirection::Incoming, MovementState::Unmatched, UnmatchedReason::Unverified, MessageTrust::Unverified, true];
        yield 'an outgoing one' => [MovementDirection::Outgoing, MovementState::Unmatched, UnmatchedReason::NoInvoice, MessageTrust::Verified, false];
        yield 'a paired one' => [MovementDirection::Incoming, MovementState::Paired, null, MessageTrust::Verified, false];
        yield 'a rejected one' => [MovementDirection::Incoming, MovementState::Unmatched, UnmatchedReason::Rejected, MessageTrust::Verified, false];
        yield 'one whose message failed' => [MovementDirection::Incoming, MovementState::Unmatched, UnmatchedReason::NoInvoice, MessageTrust::Failed, false];
    }

    #[DataProvider('rows')]
    public function testOnlyAnIncomingMovementWaitingWhoseMessageDidNotFailIsPairable(MovementDirection $direction, MovementState $state, ?UnmatchedReason $reason, MessageTrust $trust, bool $pairable): void
    {
        $row = new MovementRow('m', 'f', 'Example Firm s.r.o.', 'a', 'Main', 'CZ6508000000192000145399', $direction, Money::of('10.00', Currency::CZK), new \DateTimeImmutable('2026-11-20'), null, null, null, null, $state, $reason, $trust, null, []);

        self::assertSame($pairable, $row->isPairable());
    }

    public function testTheFilterStartsOnTheFirstPageOfIncomingMovements(): void
    {
        $filter = new MovementFilter();

        self::assertSame([MovementFilter::INCOMING, null, null, '', 1, null], [$filter->tab, $filter->firmId, $filter->accountId, $filter->text, $filter->page, $filter->movementId]);
    }
}
