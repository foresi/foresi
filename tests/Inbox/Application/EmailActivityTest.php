<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\EmailActivity;
use PHPUnit\Framework\TestCase;

/**
 * The text of the e-mail activity: the sender, the date and the mail, within
 * the 20,000 characters an activity holds (call P8).
 */
final class EmailActivityTest extends TestCase
{
    public function testAShortBodyIsTheSenderTheDateAndTheText(): void
    {
        self::assertSame("From: client.a@client-a.example.com\nDate: 2026-11-25 10:00\n\nHello,\nthe offer.\n", EmailActivity::cut('client.a@client-a.example.com', '2026-11-25 10:00', "Hello,\r\nthe offer.\r\n"));
    }

    public function testALongBodyIsCutWithTheClosingLine(): void
    {
        $body = EmailActivity::cut('client.a@client-a.example.com', '2026-11-25 10:00', str_repeat('ž', 30000));

        self::assertSame(EmailActivity::MAX_BODY, mb_strlen($body), 'Characters, not bytes.');
        self::assertStringStartsWith("From: client.a@client-a.example.com\nDate: 2026-11-25 10:00\n\nžž", $body);
        self::assertStringEndsWith("ž\n\nFull e-mail in the inbox item", $body);
    }

    public function testABodyOfExactlyTheLimitIsNotCut(): void
    {
        $head = "From: a@example.com\nDate: 2026-11-25 10:00\n\n";
        $body = EmailActivity::cut('a@example.com', '2026-11-25 10:00', str_repeat('x', EmailActivity::MAX_BODY - mb_strlen($head)));

        self::assertSame(EmailActivity::MAX_BODY, mb_strlen($body));
        self::assertStringEndsNotWith(EmailActivity::CUT, $body);
    }

    public function testTheSubjectIsOneLineCutToTwoHundredOrTheSender(): void
    {
        self::assertSame('Offer for the new office', EmailActivity::subject("  Offer for\r\n the new\toffice ", 'a@example.com'));
        self::assertSame(str_repeat('a', 200), EmailActivity::subject(str_repeat('a', 300), null));
        self::assertSame('a@example.com', EmailActivity::subject('  ', 'a@example.com'));
        self::assertSame('E-mail', EmailActivity::subject(null, null));
    }
}
