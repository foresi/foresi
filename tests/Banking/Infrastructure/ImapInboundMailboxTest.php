<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Application\MailboxItem;
use App\Banking\Infrastructure\Imap\ImapInboundMailbox;
use App\Tests\Banking\Support\InboundMailKit;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use PHPUnit\Framework\TestCase;

/**
 * The IMAP adapter against a scripted server: the library's own fake stream
 * answers as an IMAP server would, so the real client code runs and no
 * network is touched. It checks what the adapter asks for, in what order,
 * and that the message comes back byte for byte.
 */
final class ImapInboundMailboxTest extends TestCase
{
    private const string PASSWORD = 'not-a-real-password';

    public function testImapAdapterBuildsTheClientFromSettingsWithoutConnecting(): void
    {
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX');

        $client = $adapter->client();

        self::assertFalse($client->connected());
        self::assertSame('imap.example.com', $client->config('host'));
        self::assertSame(993, $client->config('port'));
        self::assertSame('ssl', $client->config('encryption'));
        self::assertSame('bank@example.com', $client->config('username'));
        self::assertSame(self::PASSWORD, $client->config('password'));
        self::assertTrue($client->config('validate_cert'));
        self::assertFalse($client->config('debug'));
        self::assertSame('plain', $client->config('authentication'));
    }

    public function testStarttlsIsAcceptedAndAnythingElseRefused(): void
    {
        self::assertSame('starttls', new ImapInboundMailbox('imap.example.com', 143, 'STARTTLS', 'bank@example.com', self::PASSWORD, 'INBOX')->client()->config('encryption'));

        $this->expectException(\InvalidArgumentException::class);
        new ImapInboundMailbox('imap.example.com', 143, 'none', 'bank@example.com', self::PASSWORD, 'INBOX');
    }

    public function testThePasswordIsNotInTheAdaptersDump(): void
    {
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX');

        self::assertStringNotContainsString(self::PASSWORD, print_r($adapter, true));
    }

    public function testFetchCreatesTheFoldersAndReadsTheOldestMessagesWhole(): void
    {
        // The body starts with blank lines, which a client that trims would lose.
        $first = InboundMailKit::mail('bank+abcdefghijklmnopqrstuvwxyz@example.com', 'first@mail.example.com', body: "\r\n\r\nFirst payment.\r\n");
        $second = InboundMailKit::mail('bank+abcdefghijklmnopqrstuvwxyz@example.com', 'second@mail.example.com');
        $stream = new FakeStream();
        $stream->feed([
            '* OK IMAP ready',
            'TAG1 OK LOGIN completed',
            '* LIST (\HasChildren) "/" "INBOX"',
            'TAG2 OK LIST completed',
            '* LIST (\HasNoChildren) "/" "Foresi/Done"',
            'TAG3 OK LIST completed',
            'TAG4 OK LIST completed',
            'TAG5 OK CREATE completed',
            '* LIST (\HasNoChildren) "/" "Foresi/Unknown"',
            'TAG6 OK LIST completed',
            '* LIST (\HasNoChildren) "/" "Foresi/Rejected"',
            'TAG7 OK LIST completed',
            '* 3 EXISTS',
            'TAG8 OK [READ-WRITE] SELECT completed',
            '* SEARCH 9 3 4',
            'TAG9 OK SEARCH completed',
        ]);
        $stream->feedRaw($this->fetched(1, 3, $first).$this->fetched(2, 4, $second));
        $stream->feed([
            'TAG10 OK FETCH completed',
            '* CAPABILITY IMAP4rev1 UIDPLUS MOVE',
            'TAG11 OK CAPABILITY completed',
            '* OK [COPYUID 1 3 7] Moved',
            'TAG12 OK MOVE completed',
            '* OK [COPYUID 1 4 2] Moved',
            'TAG13 OK MOVE completed',
        ]);
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new ImapConnection($stream));

        $items = $adapter->fetch(2);

        self::assertEquals([new MailboxItem('3', $first), new MailboxItem('4', $second)], $items);
        $stream->assertWritten('TAG1 LOGIN "bank@example.com" "'.self::PASSWORD.'"');
        $stream->assertWritten('TAG2 LIST "" "INBOX"');
        $stream->assertWritten('TAG3 LIST "" "Foresi/Done"');
        $stream->assertWritten('TAG5 CREATE "Foresi/Unknown"');
        $stream->assertWritten('TAG8 SELECT "INBOX"');
        $stream->assertWritten('TAG9 UID SEARCH UNDELETED');
        // Peeked, so the messages stay unread for an operator who looks.
        $stream->assertWritten('TAG10 UID FETCH 3:4 (BODY.PEEK[HEADER] BODY.PEEK[TEXT])');

        $adapter->markDone('3');
        $adapter->markRejected('4');

        $stream->assertWritten('TAG12 UID MOVE 3 "Foresi/Done"');
        $stream->assertWritten('TAG13 UID MOVE 4 "Foresi/Rejected"');
    }

    public function testTheFoldersFollowTheServersDelimiter(): void
    {
        $stream = new FakeStream();
        $stream->feed([
            '* OK IMAP ready',
            'TAG1 OK LOGIN completed',
            '* LIST (\HasChildren) "." "INBOX"',
            'TAG2 OK LIST completed',
            '* LIST () "." "Foresi.Done"',
            'TAG3 OK LIST completed',
            '* LIST () "." "Foresi.Unknown"',
            'TAG4 OK LIST completed',
            '* LIST () "." "Foresi.Rejected"',
            'TAG5 OK LIST completed',
            'TAG6 OK SELECT completed',
            '* SEARCH',
            'TAG7 OK SEARCH completed',
        ]);
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new ImapConnection($stream));

        self::assertSame([], $adapter->fetch(50));
        $stream->assertWritten('TAG3 LIST "" "Foresi.Done"');
    }

    public function testAMessageNotFetchedInThisRunCannotBeMoved(): void
    {
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX');

        $this->expectException(\LogicException::class);
        $adapter->markDone('3');
    }

    private function fetched(int $sequence, int $uid, string $raw): string
    {
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $head .= "\r\n\r\n";

        return \sprintf("* %d FETCH (UID %d BODY[HEADER] {%d}\r\n%s BODY[TEXT] {%d}\r\n%s)\r\n", $sequence, $uid, \strlen($head), $head, \strlen($body), $body);
    }
}
