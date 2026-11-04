<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\MessageRow;
use App\Banking\Application\ProcessMessage;
use App\Banking\Domain\InboundStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which actions the Messages tab offers on a row.
 */
final class MessageRowTest extends TestCase
{
    /**
     * @return iterable<string, array{InboundStatus, ?string, bool, array{bool, bool, bool}}>
     */
    public static function actions(): iterable
    {
        // [status, failure, stuck] → [retry, accept, dismiss]
        yield 'waiting in the queue' => [InboundStatus::Received, null, false, [false, false, false]];
        yield 'left waiting' => [InboundStatus::Received, null, true, [true, false, true]];
        yield 'being processed' => [InboundStatus::Processing, null, false, [false, false, false]];
        yield 'processed' => [InboundStatus::Processed, null, false, [false, false, false]];
        yield 'processed though once stuck' => [InboundStatus::Processed, null, true, [true, false, false]];
        yield 'dismissed' => [InboundStatus::Dismissed, null, false, [false, false, false]];
        yield 'failed' => [InboundStatus::Failed, ProcessMessage::UNREADABLE, false, [true, false, true]];
        yield 'failed as untrusted' => [InboundStatus::Failed, ProcessMessage::UNTRUSTED, false, [true, false, true]];
        yield 'no bank mail' => [InboundStatus::NotBankMail, null, false, [true, false, true]];
        yield 'rejected sender' => [InboundStatus::Rejected, ProcessMessage::UNTRUSTED, false, [false, true, true]];
        yield 'account not the firm\'s' => [InboundStatus::Rejected, ProcessMessage::ACCOUNT_MISMATCH, false, [false, false, true]];
    }

    /**
     * @param array{bool, bool, bool} $expected
     */
    #[DataProvider('actions')]
    public function testOffersTheActionsOfItsStatus(InboundStatus $status, ?string $failure, bool $stuck, array $expected): void
    {
        $row = new MessageRow('00000000-0000-4000-8000-000000000001', new \DateTimeImmutable('2026-11-20 10:00:00'), 'bank@example.com', 'Payment', $status, $failure, null, null, null, null, null, 1, 'Example Firm s.r.o.', 'CZ', 'Main', 'CZ6508000000192000145399', $stuck);

        self::assertSame($expected, [$row->canRetry(), $row->canAccept(), $row->canDismiss()]);
    }
}
