<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\BankMessageParsers;
use Foresi\Banking\Domain\InboundStatus;
use Foresi\Tests\Banking\Support\InboundMailKit;
use Foresi\Tests\Banking\Support\InboundTestCase;

/**
 * The pipeline from a stored message to recorded movements, one step at a time:
 * whose account it is, what a parser could not read, what someone decided, and
 * that processing a message again never records it twice.
 * The tests that need the Czech rules are in Integration/Country.
 */
final class ProcessMessageTest extends InboundTestCase
{
    public function testAMailNoParserKnowsIsNotBankMail(): void
    {
        // Such as the confirmation a mailbox sends before it forwards anything.
        $id = $this->received(InboundMailKit::mail($this->address));

        self::assertSame(InboundStatus::NotBankMail, $this->process->process($id));

        $row = $this->messageRow($id);
        self::assertSame(['notbankmail', self::getContainer()->get(BankMessageParsers::class)->signature(), null, null], [$row['status'], $row['parser_id'], $row['parser_version'], $row['trust']]);
        self::assertSame([], $this->movementsOf($id));
    }
}
