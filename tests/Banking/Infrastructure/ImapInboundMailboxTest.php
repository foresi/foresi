<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Application\MailboxItem;
use App\Banking\Infrastructure\Imap\ImapInboundMailbox;
use App\Banking\Infrastructure\Imap\NamespacedImapConnection;
use App\Tests\Banking\Support\InboundMailKit;
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
            '* NAMESPACE (("" "/")) NIL NIL',
            'TAG3 OK NAMESPACE completed',
            '* LIST (\HasNoChildren) "/" "Foresi/Done"',
            'TAG4 OK LIST completed',
            'TAG5 OK LIST completed',
            'TAG6 OK CREATE completed',
            '* LIST (\HasNoChildren) "/" "Foresi/Unknown"',
            'TAG7 OK LIST completed',
            '* LIST (\HasNoChildren) "/" "Foresi/Rejected"',
            'TAG8 OK LIST completed',
            '* 3 EXISTS',
            'TAG9 OK [READ-WRITE] SELECT completed',
            '* SEARCH 9 3 4',
            'TAG10 OK SEARCH completed',
        ]);
        $stream->feedRaw($this->fetched(1, 3, $first).$this->fetched(2, 4, $second));
        $stream->feed([
            'TAG11 OK FETCH completed',
            '* CAPABILITY IMAP4rev1 UIDPLUS MOVE',
            'TAG12 OK CAPABILITY completed',
            '* OK [COPYUID 1 3 7] Moved',
            'TAG13 OK MOVE completed',
            '* OK [COPYUID 1 4 2] Moved',
            'TAG14 OK MOVE completed',
        ]);
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new NamespacedImapConnection($stream));

        $items = $adapter->fetch(2);

        self::assertEquals([new MailboxItem('3', $first), new MailboxItem('4', $second)], $items);
        $stream->assertWritten('TAG1 LOGIN "bank@example.com" "'.self::PASSWORD.'"');
        $stream->assertWritten('TAG2 LIST "" "INBOX"');
        $stream->assertWritten('TAG3 NAMESPACE');
        $stream->assertWritten('TAG4 LIST "" "Foresi/Done"');
        $stream->assertWritten('TAG6 CREATE "Foresi/Unknown"');
        $stream->assertWritten('TAG9 SELECT "INBOX"');
        $stream->assertWritten('TAG10 UID SEARCH UNDELETED');
        // Peeked, so the messages stay unread for an operator who looks.
        $stream->assertWritten('TAG11 UID FETCH 3:4 (BODY.PEEK[HEADER] BODY.PEEK[TEXT])');

        $adapter->markDone('3');
        $adapter->markRejected('4');

        $stream->assertWritten('TAG13 UID MOVE 3 "Foresi/Done"');
        $stream->assertWritten('TAG14 UID MOVE 4 "Foresi/Rejected"');
    }

    public function testTheFoldersFollowTheServersDelimiterWhenItHasNoNamespaces(): void
    {
        $stream = new FakeStream();
        $stream->feed([
            '* OK IMAP ready',
            'TAG1 OK LOGIN completed',
            '* LIST (\HasChildren) "." "INBOX"',
            'TAG2 OK LIST completed',
            // A server of RFC 3501 alone does not know the command: the folders go to the top.
            'TAG3 BAD Unknown command',
            '* LIST () "." "Foresi.Done"',
            'TAG4 OK LIST completed',
            '* LIST () "." "Foresi.Unknown"',
            'TAG5 OK LIST completed',
            '* LIST () "." "Foresi.Rejected"',
            'TAG6 OK LIST completed',
            'TAG7 OK SELECT completed',
            '* SEARCH',
            'TAG8 OK SEARCH completed',
        ]);
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new NamespacedImapConnection($stream));

        self::assertSame([], $adapter->fetch(50));
        $stream->assertWritten('TAG4 LIST "" "Foresi.Done"');
    }

    public function testTheFoldersGoUnderThePersonalNamespace(): void
    {
        // Courier and Cyrus keep every folder of the user below INBOX.
        $raw = InboundMailKit::mail('bank+abcdefghijklmnopqrstuvwxyz@example.com', 'first@mail.example.com');
        $stream = new FakeStream();
        $stream->feed([
            '* OK IMAP ready',
            'TAG1 OK LOGIN completed',
            '* LIST (\HasNoChildren) "." "INBOX"',
            'TAG2 OK LIST completed',
            '* NAMESPACE (("INBOX." ".")) NIL (("#shared." "."))',
            'TAG3 OK NAMESPACE completed',
            'TAG4 OK LIST completed',
            'TAG5 OK CREATE completed',
            '* LIST () "." "INBOX.Foresi.Done"',
            'TAG6 OK LIST completed',
            'TAG7 OK LIST completed',
            'TAG8 OK CREATE completed',
            '* LIST () "." "INBOX.Foresi.Unknown"',
            'TAG9 OK LIST completed',
            '* LIST () "." "INBOX.Foresi.Rejected"',
            'TAG10 OK LIST completed',
            '* 1 EXISTS',
            'TAG11 OK [READ-WRITE] SELECT completed',
            '* SEARCH 5',
            'TAG12 OK SEARCH completed',
        ]);
        $stream->feedRaw($this->fetched(1, 5, $raw));
        $stream->feed([
            'TAG13 OK FETCH completed',
            '* CAPABILITY IMAP4rev1 UIDPLUS MOVE NAMESPACE',
            'TAG14 OK CAPABILITY completed',
            'TAG15 OK MOVE completed',
        ]);
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new NamespacedImapConnection($stream));

        self::assertEquals([new MailboxItem('5', $raw)], $adapter->fetch(50));
        $adapter->markUnknown('5');

        $stream->assertWritten('TAG3 NAMESPACE');
        $stream->assertWritten('TAG4 LIST "" "INBOX.Foresi.Done"');
        $stream->assertWritten('TAG5 CREATE "INBOX.Foresi.Done"');
        $stream->assertWritten('TAG8 CREATE "INBOX.Foresi.Unknown"');
        $stream->assertWritten('TAG10 LIST "" "INBOX.Foresi.Rejected"');
        $stream->assertWritten('TAG15 UID MOVE 5 "INBOX.Foresi.Unknown"');
    }

    /**
     * A server of RFC 3501 alone knows neither MOVE nor UIDPLUS: the message is
     * copied and flagged deleted, and the inbox is never expunged.
     */
    public function testAServerWithoutMoveGetsACopyAndADeletedFlag(): void
    {
        $raw = InboundMailKit::mail('bank+abcdefghijklmnopqrstuvwxyz@example.com', 'first@mail.example.com');
        $stream = new FakeStream();
        $stream->feed([
            '* OK IMAP ready',
            'TAG1 OK LOGIN completed',
            '* LIST (\HasChildren) "/" "INBOX"',
            'TAG2 OK LIST completed',
            'TAG3 BAD Unknown command',
            '* LIST () "/" "Foresi/Done"',
            'TAG4 OK LIST completed',
            '* LIST () "/" "Foresi/Unknown"',
            'TAG5 OK LIST completed',
            '* LIST () "/" "Foresi/Rejected"',
            'TAG6 OK LIST completed',
            '* 1 EXISTS',
            'TAG7 OK [READ-WRITE] SELECT completed',
            '* SEARCH 8',
            'TAG8 OK SEARCH completed',
        ]);
        $stream->feedRaw($this->fetched(1, 8, $raw));
        $stream->feed([
            'TAG9 OK FETCH completed',
            '* CAPABILITY IMAP4rev1',
            'TAG10 OK CAPABILITY completed',
            'TAG11 OK COPY completed',
            'TAG12 OK STORE completed',
        ]);
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new NamespacedImapConnection($stream));

        self::assertEquals([new MailboxItem('8', $raw)], $adapter->fetch(50));
        $adapter->markDone('8');

        $stream->assertWritten('TAG11 UID COPY 8 "Foresi/Done"');
        $stream->assertWritten('TAG12 UID STORE 8 +FLAGS.SILENT (\Deleted)');
    }

    public function testMessagesGivenUpOnAreLeftOutOfTheSearch(): void
    {
        $stream = new FakeStream();
        $stream->feed([
            '* OK IMAP ready',
            'TAG1 OK LOGIN completed',
            '* LIST (\HasChildren) "/" "INBOX"',
            'TAG2 OK LIST completed',
            'TAG3 BAD Unknown command',
            '* LIST () "/" "Foresi/Done"',
            'TAG4 OK LIST completed',
            '* LIST () "/" "Foresi/Unknown"',
            'TAG5 OK LIST completed',
            '* LIST () "/" "Foresi/Rejected"',
            'TAG6 OK LIST completed',
            'TAG7 OK SELECT completed',
            '* SEARCH',
            'TAG8 OK SEARCH completed',
        ]);
        $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new NamespacedImapConnection($stream));

        self::assertSame([], $adapter->fetch(50, ['3', '7', 'not-a-uid']));

        $stream->assertWritten('TAG8 UID SEARCH UNDELETED NOT UID 3,7');
    }

    public function testAFailedLoginNeverShowsThePasswordEvenWithArgumentsInTraces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $stream = new FakeStream();
            $stream->feed([
                '* OK IMAP ready',
                'TAG1 NO [AUTHENTICATIONFAILED] Invalid credentials',
            ]);
            $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new NamespacedImapConnection($stream));

            try {
                $adapter->fetch(50);
                self::fail('A refused login fails the run.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('Invalid credentials', $failure->getMessage());
                self::assertNull($failure->getPrevious(), 'the library exception, whose trace holds the password, is not kept');
                // Every argument the trace kept, as a log or an error page would print it.
                $arguments = [];
                foreach ($failure->getTrace() as $frame) {
                    foreach ($frame['args'] ?? [] as $argument) {
                        $arguments[] = \is_scalar($argument) ? (string) $argument : get_debug_type($argument);
                    }
                }
                self::assertNotSame([], $arguments, 'the trace keeps arguments');
                foreach ([(string) $failure, $failure->getTraceAsString(), implode("\n", $arguments)] as $shown) {
                    self::assertStringNotContainsString(self::PASSWORD, $shown);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
    }

    /**
     * Past the login the library still holds the password, and reconnects with it
     * as an argument when a connection drops: no library exception is passed on
     * from any step, only one in words without the password.
     */
    public function testAFailureAfterTheLoginNeverCarriesTheLibrarysTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $stream = new FakeStream();
            $stream->feed([
                '* OK IMAP ready',
                'TAG1 OK LOGIN completed',
                'TAG2 NO [SERVERBUG] '.self::PASSWORD.' is not a folder',
            ]);
            $adapter = new ImapInboundMailbox('imap.example.com', 993, 'ssl', 'bank@example.com', self::PASSWORD, 'INBOX', new NamespacedImapConnection($stream));

            try {
                $adapter->fetch(50);
                self::fail('A refused command fails the run.');
            } catch (\RuntimeException $failure) {
                self::assertNull($failure->getPrevious());
                foreach ([(string) $failure, $failure->getTraceAsString()] as $shown) {
                    self::assertStringNotContainsString(self::PASSWORD, $shown);
                }
                self::assertStringContainsString('[redacted]', $failure->getMessage());
            }
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
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
