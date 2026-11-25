<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\ForwardParser;
use Foresi\Inbox\Domain\ForwardedSender;
use Foresi\Tests\Inbox\Support\InboxMail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The original sender of forwards as Gmail, Outlook and Apple Mail write them,
 * in English and Czech. Synthetic bodies only.
 */
final class ForwardParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forwards(): iterable
    {
        yield 'Gmail' => [InboxMail::gmailForward(), 'Client A'];
        yield 'Gmail in Czech' => [InboxMail::gmailForwardCzech(), 'Klient A'];
        yield 'Outlook desktop' => [InboxMail::outlookDesktopForward(), 'Client A'];
        yield 'Outlook desktop in Czech' => [InboxMail::outlookDesktopForwardCzech(), 'Klient A'];
        yield 'Outlook web' => [InboxMail::outlookWebForward(), 'Client A'];
        yield 'Outlook web in Czech' => [InboxMail::outlookWebForwardCzech(), 'Klient A'];
        yield 'Apple Mail' => [InboxMail::appleMailForward(), 'Client A'];
        yield 'Apple Mail in Czech' => [InboxMail::appleMailForwardCzech(), 'Klient A'];
    }

    #[DataProvider('forwards')]
    public function testFindsTheOriginalSenderOfAForward(string $body, string $name): void
    {
        $sender = new ForwardParser()->parse($body);

        self::assertNotNull($sender);
        self::assertSame('client.a@client-a.example.com', $sender->address);
        self::assertSame($name, $sender->name);
    }

    public function testReadsEveryFormOfTheSender(): void
    {
        $parser = new ForwardParser();
        $of = static fn (string $from): ?array => (($sender = $parser->parse(InboxMail::gmailForward($from))) instanceof ForwardedSender) ? [$sender->address, $sender->name] : null;

        self::assertSame(['client.a@client-a.example.com', 'Client A'], $of('"Client A" <Client.A@Client-A.example.com>'));
        self::assertSame(['client.a@client-a.example.com', null], $of('client.a@client-a.example.com'));
        self::assertSame(['client.a@client-a.example.com', null], $of('<client.a@client-a.example.com>'));
        self::assertSame(['client.a@client-a.example.com', 'Client A'], $of('Client A [mailto:client.a@client-a.example.com]'));
        self::assertSame(['client.a@client-a.example.com', null], $of('client.a@client-a.example.com <client.a@client-a.example.com>'));
        self::assertNull($of('Client A'), 'A From line without an address names nobody.');
    }

    public function testAQuotedBlockIsReadToo(): void
    {
        $body = "See below.\r\n\r\n> ---------- Forwarded message ---------\r\n> From: Client A <client.a@client-a.example.com>\r\n> Date: Mon, Nov 23, 2026\r\n";

        self::assertSame('client.a@client-a.example.com', new ForwardParser()->parse($body)?->address);
    }

    public function testTheTopmostBlockCountsInAForwardOfAForward(): void
    {
        $body = InboxMail::gmailForward('Colleague B <colleague.b@example.com>')."\r\n".InboxMail::outlookWebForward();

        self::assertSame('colleague.b@example.com', new ForwardParser()->parse($body)?->address);
    }

    public function testAMailWithoutAKnownForwardBlockNamesNobody(): void
    {
        $parser = new ForwardParser();

        self::assertNull($parser->parse(null));
        self::assertNull($parser->parse(''));
        self::assertNull($parser->parse("Hello,\r\nFrom: Client A <client.a@client-a.example.com>\r\nwas in my notes.\r\n"), 'A From line outside a block is text.');
        self::assertNull($parser->parse("Weitergeleitete Nachricht:\r\nVon: Client A <client.a@client-a.example.com>\r\n"), 'Only English and Czech blocks are known.');
        self::assertNull($parser->parse("---------- Forwarded message ---------\r\nDate: Mon, Nov 23, 2026\r\n\r\nFrom: Client A <client.a@client-a.example.com>\r\n"), 'A block that ends before its From line names nobody.');
        self::assertNull($parser->parse("________________________________\r\nThe quarterly report is attached.\r\nFrom: Client A <client.a@client-a.example.com>\r\n"), 'A line of underscores above text is not a block.');
    }
}
