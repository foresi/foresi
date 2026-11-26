<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

/**
 * Synthetic raw mail for the tests of Inbox: any sender, replies and forwards
 * as the common mail programs write them. Nothing is a real message:
 * example.com names and made-up people only.
 */
final readonly class InboxMail
{
    /** What a receiving server trusted in the test settings writes for a verified sender. */
    public static function verifiedBy(string $domain): string
    {
        return 'mx.example.com; dkim=pass header.d='.$domain.'; dmarc=pass header.from='.$domain;
    }

    /**
     * @param list<string>          $references Message-IDs of the References header, oldest first
     * @param array<string, string> $headers    more header fields, placed first as a receiving server would
     */
    public static function plain(
        string $to,
        string $from = 'Client A <client.a@client-a.example.com>',
        string $subject = 'Offer for the new office',
        ?string $messageId = 'offer-1@client-a.example.com',
        string $body = "Hello, please find our offer below.\r\n",
        ?string $inReplyTo = null,
        array $references = [],
        array $headers = [],
    ): string {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $lines[] = 'From: '.$from;
        $lines[] = 'To: '.$to;
        $lines[] = 'Subject: '.$subject;
        $lines[] = 'Date: Tue, 24 Nov 2026 09:30:00 +0100';
        if (null !== $messageId) {
            $lines[] = 'Message-ID: <'.$messageId.'>';
        }
        if (null !== $inReplyTo) {
            $lines[] = 'In-Reply-To: <'.$inReplyTo.'>';
        }
        if ([] !== $references) {
            $lines[] = 'References: '.implode(' ', array_map(static fn (string $id): string => '<'.$id.'>', $references));
        }
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: text/plain; charset=utf-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';

        return implode("\r\n", $lines)."\r\n\r\n".$body;
    }

    /** The body of a forward from Gmail, in English. */
    public static function gmailForward(string $originalFrom = 'Client A <client.a@client-a.example.com>'): string
    {
        return "FYI, see below.\r\n\r\n---------- Forwarded message ---------\r\nFrom: ".$originalFrom."\r\nDate: Mon, Nov 23, 2026 at 4:12 PM\r\nSubject: Offer for the new office\r\nTo: Owner Example <owner@example.com>\r\n\r\n\r\nHello, please find our offer below.\r\n";
    }

    /** The body of a forward from Gmail, in Czech. */
    public static function gmailForwardCzech(string $originalFrom = 'Klient A <client.a@client-a.example.com>'): string
    {
        return "Posílám dál.\r\n\r\n---------- Přeposlaná zpráva ---------\r\nOd: ".$originalFrom."\r\nDate: po 23. 11. 2026 v 16:12\r\nSubject: Nabídka\r\nTo: <owner@example.com>\r\n\r\nDobrý den, posíláme nabídku.\r\n";
    }

    /** The body of a forward from Outlook on the desktop, in English, the older style. */
    public static function outlookDesktopForward(): string
    {
        return "Please have a look.\r\n\r\n-----Original Message-----\r\nFrom: Client A [mailto:client.a@client-a.example.com]\r\nSent: Monday, November 23, 2026 4:12 PM\r\nTo: Owner Example <owner@example.com>\r\nSubject: Offer for the new office\r\n\r\nHello, please find our offer below.\r\n";
    }

    /** The body of a forward from Outlook on the desktop, in Czech, the older style. */
    public static function outlookDesktopForwardCzech(): string
    {
        return "Prosím o kontrolu.\r\n\r\n-----Původní zpráva-----\r\nOd: Klient A [mailto:client.a@client-a.example.com]\r\nOdesláno: pondělí 23. listopadu 2026 16:12\r\nKomu: Owner Example <owner@example.com>\r\nPředmět: Nabídka\r\n\r\nDobrý den, posíláme nabídku.\r\n";
    }

    /** The body of a forward from Outlook on the web (and newer desktop versions), in English. */
    public static function outlookWebForward(): string
    {
        return "See below.\r\n\r\n________________________________\r\nFrom: Client A <client.a@client-a.example.com>\r\nSent: Monday, November 23, 2026 16:12\r\nTo: Owner Example <owner@example.com>\r\nSubject: Offer for the new office\r\n\r\nHello, please find our offer below.\r\n";
    }

    /** The body of a forward from Outlook on the web, in Czech. */
    public static function outlookWebForwardCzech(): string
    {
        return "Viz níže.\r\n\r\n________________________________\r\nOd: Klient A <client.a@client-a.example.com>\r\nOdesláno: pondělí 23. listopadu 2026 16:12\r\nKomu: Owner Example <owner@example.com>\r\nPředmět: Nabídka\r\n\r\nDobrý den, posíláme nabídku.\r\n";
    }

    /** The body of a forward from Apple Mail, in English. */
    public static function appleMailForward(): string
    {
        return "Forwarding this.\r\n\r\nBegin forwarded message:\r\n\r\nFrom: Client A <client.a@client-a.example.com>\r\nSubject: Offer for the new office\r\nDate: 23 November 2026 at 16:12:00 CET\r\nTo: Owner Example <owner@example.com>\r\n\r\nHello, please find our offer below.\r\n";
    }

    /** The body of a forward from Apple Mail, in Czech. */
    public static function appleMailForwardCzech(): string
    {
        return "Přeposílám.\r\n\r\nZačátek přeposílané zprávy:\r\n\r\nOd: Klient A <client.a@client-a.example.com>\r\nPředmět: Nabídka\r\nDatum: 23. listopadu 2026 v 16:12:00 SEČ\r\nKomu: Owner Example <owner@example.com>\r\n\r\nDobrý den, posíláme nabídku.\r\n";
    }
}
