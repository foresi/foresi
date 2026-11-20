<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\MovementFilter;
use Foresi\Banking\Application\MovementRow;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Domain\MessageTrust;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
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
        yield 'an outgoing one waiting' => [MovementDirection::Outgoing, MovementState::Unmatched, UnmatchedReason::NoInvoice, MessageTrust::Verified, true];
        yield 'an outgoing one recorded only before refunds paired by themselves' => [MovementDirection::Outgoing, MovementState::Recorded, null, MessageTrust::Verified, true];
        yield 'an incoming one recorded' => [MovementDirection::Incoming, MovementState::Recorded, null, MessageTrust::Verified, false];
        yield 'an outgoing own transfer' => [MovementDirection::Outgoing, MovementState::OwnTransfer, null, MessageTrust::Verified, false];
        yield 'a paired one' => [MovementDirection::Incoming, MovementState::Paired, null, MessageTrust::Verified, false];
        yield 'a rejected one' => [MovementDirection::Incoming, MovementState::Unmatched, UnmatchedReason::Rejected, MessageTrust::Verified, false];
        yield 'one whose message failed' => [MovementDirection::Incoming, MovementState::Unmatched, UnmatchedReason::NoInvoice, MessageTrust::Failed, false];
    }

    #[DataProvider('rows')]
    public function testOnlyAMovementWaitingWhoseMessageDidNotFailIsPairable(MovementDirection $direction, MovementState $state, ?UnmatchedReason $reason, MessageTrust $trust, bool $pairable): void
    {
        $row = new MovementRow('m', 'a', 'Main', 'CZ6508000000192000145399', $direction, Money::of('10.00', Currency::CZK), new \DateTimeImmutable('2026-11-20'), null, null, null, null, $state, $reason, $trust, null, []);

        self::assertSame($pairable, $row->isPairable());
    }

    public function testTheFilterStartsOnTheFirstPageOfEveryMovement(): void
    {
        $filter = new MovementFilter();

        self::assertSame([[], '', 1, null, [], false], [$filter->view->conditions, $filter->text, $filter->page, $filter->movementId, $filter->counted, $filter->mail()]);
    }
}
