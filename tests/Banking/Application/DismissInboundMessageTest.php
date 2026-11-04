<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\DismissInboundMessage;
use App\Banking\Application\UnknownInboundMessage;
use App\Banking\Domain\InboundStatus;
use App\Shared\Domain\Id;
use App\Tests\Banking\Support\InboundTestCase;

/**
 * Setting a message aside says whether it did: once, and never for a message still in the queue.
 */
final class DismissInboundMessageTest extends InboundTestCase
{
    public function testAMailNoParserKnewIsDismissedOnce(): void
    {
        $id = $this->received($this->notice('not-kb'));
        self::assertSame(InboundStatus::NotBankMail, $this->process->process($id));
        $dismiss = self::getContainer()->get(DismissInboundMessage::class);

        self::assertTrue($dismiss->dismiss($id));
        self::assertSame('dismissed', $this->messageRow($id)['status']);

        self::assertFalse($dismiss->dismiss($id), 'Dismissed already.');
        self::assertSame('dismissed', $this->messageRow($id)['status']);
    }

    public function testAMessageStillInTheQueueIsNotDismissed(): void
    {
        $id = $this->received($this->notice('incoming-booked'));

        self::assertFalse(self::getContainer()->get(DismissInboundMessage::class)->dismiss($id));
        self::assertSame('received', $this->messageRow($id)['status']);
    }

    public function testAnUnknownMessageIsRefused(): void
    {
        $this->expectException(UnknownInboundMessage::class);
        $this->expectExceptionMessageIsOrContains('No such inbound message.');
        self::getContainer()->get(DismissInboundMessage::class)->dismiss(Id::generate());
    }
}
