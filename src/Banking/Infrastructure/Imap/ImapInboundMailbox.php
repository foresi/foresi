<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Imap;

use App\Banking\Application\InboundMailbox;
use App\Banking\Application\MailboxItem;
use DirectoryTree\ImapEngine\Connection\RawQueryValue;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use DirectoryTree\ImapEngine\Enums\ImapSearchKey;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;

/**
 * The operator mailbox over IMAP with directorytree/imapengine, the only code
 * that touches the library (and the Laravel collections and Carbon it brings).
 *
 * Every fetch opens a fresh session, so a connection the server dropped since
 * the last run does no harm. It makes sure the three folders exist, in the
 * user's personal namespace when the server names one (below "INBOX." on
 * Courier and Cyrus) and under the server's own hierarchy delimiter, then reads the oldest messages that are not
 * marked deleted and not given up on, by UID, whole and peeked, so they stay
 * unread. A message is moved with MOVE, or by the library's COPY and deleted flag
 * on a server with UIDPLUS, or by a plain COPY and a deleted flag here on a server
 * with neither; the inbox is never expunged, which would remove what an operator
 * deleted.
 *
 * The password stays out of dumps and stack traces.
 */
final class ImapInboundMailbox implements InboundMailbox
{
    public const string PARENT = 'Foresi';
    private const array FOLDERS = ['done' => 'Done', 'unknown' => 'Unknown', 'rejected' => 'Rejected'];
    private const array ENCRYPTIONS = ['ssl', 'starttls'];
    private const int TIMEOUT = 30;

    private readonly string $encryption;
    private ?Mailbox $mailbox = null;
    /** @var array<string, string> folder paths by FOLDERS key */
    private array $folders = [];
    /** @var array<int, Message> the messages of the last fetch, by UID */
    private array $fetched = [];

    /**
     * @param NamespacedImapConnection|null $connection a connection to use in place of a socket, for a scripted server in tests
     *
     * @throws \InvalidArgumentException for an encryption other than ssl or starttls
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        string $encryption,
        private readonly string $user,
        #[\SensitiveParameter]
        private readonly string $password,
        private readonly string $folder,
        private readonly ?NamespacedImapConnection $connection = null,
    ) {
        $encryption = strtolower(trim($encryption));
        if (!\in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new \InvalidArgumentException('BANK_INBOX_ENCRYPTION is ssl or starttls.');
        }
        $this->encryption = $encryption;
    }

    /**
     * The mailbox of these settings, or none when no host is set.
     */
    public static function fromSettings(string $host, int $port, string $encryption, string $user, #[\SensitiveParameter] string $password, string $folder): InboundMailbox
    {
        $host = trim($host);
        if ('' === $host) {
            return new DisabledInboundMailbox();
        }

        return new self($host, $port, $encryption, $user, $password, '' === trim($folder) ? 'INBOX' : trim($folder));
    }

    /**
     * The client for these settings, not connected yet.
     */
    public function client(): Mailbox
    {
        // The settings, the password among them, are an argument here: a trace of this frame would show them.
        return $this->guarded('set up', fn (): Mailbox => new Mailbox([
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->user,
            'password' => $this->password,
            'authentication' => 'plain',
            'validate_cert' => true,
            'timeout' => self::TIMEOUT,
            'debug' => false,
        ]));
    }

    public function fetch(int $limit, array $skip = []): array
    {
        $this->guarded('closed', $this->close(...));
        if ($limit < 1) {
            return [];
        }
        $mailbox = $this->client();
        $connection = $this->connection ?? new NamespacedImapConnection(new ImapStream());
        $this->guarded('opened', static function () use ($mailbox, $connection): void {
            $mailbox->connect($connection);
        });
        $this->mailbox = $mailbox;

        return $this->guarded('read', fn (): array => $this->read($mailbox, $connection, $limit, $skip));
    }

    /**
     * @param list<string> $skip
     *
     * @return list<MailboxItem>
     */
    private function read(Mailbox $mailbox, NamespacedImapConnection $connection, int $limit, array $skip): array
    {
        $folders = $mailbox->folders();
        $inbox = $folders->findOrFail($this->folder);
        [$prefix, $delimiter] = $this->home($connection, '' === $inbox->delimiter() ? '/' : $inbox->delimiter());
        foreach (self::FOLDERS as $key => $name) {
            $path = $prefix.self::PARENT.$delimiter.$name;
            $folders->firstOrCreate($path);
            $this->folders[$key] = $path;
        }

        $query = $inbox->messages();
        $query->undeleted();
        $skipped = array_values(array_filter($skip, static fn (string $uid): bool => 1 === preg_match('/^[1-9][0-9]*$/D', $uid)));
        if ([] !== $skipped) {
            $query->whereNot(ImapSearchKey::Uid, new RawQueryValue(implode(',', $skipped)));
        }
        $items = [];
        foreach ($query->oldest()->limit($limit)->withHeaders()->withBody()->get() as $message) {
            if (!$message instanceof Message) {
                continue;
            }
            $this->fetched[$message->uid()] = $message;
            // The header section ends with its blank line and the text is the rest: together the message as it was.
            $items[] = new MailboxItem((string) $message->uid(), $message->head().$message->body());
        }

        return $items;
    }

    public function markDone(string $uid): void
    {
        $this->move($uid, 'done');
    }

    public function markUnknown(string $uid): void
    {
        $this->move($uid, 'unknown');
    }

    public function markRejected(string $uid): void
    {
        $this->move($uid, 'rejected');
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'encryption' => $this->encryption, 'user' => $this->user, 'folder' => $this->folder];
    }

    /**
     * Runs one step of the library. The library holds the password: it logs in
     * with it as an argument of its own methods, on connecting and again on any
     * reconnect, and the client is built with it. A trace keeps arguments where
     * exceptions carry them (zend.exception_ignore_args off, as in development),
     * so no exception of a step is passed on: a new one says what failed, in words
     * without the password, and starts its trace here.
     *
     * @template T
     *
     * @param callable(): T $step
     *
     * @return T
     */
    private function guarded(string $doing, callable $step): mixed
    {
        try {
            return $step();
        } catch (\Throwable $failure) {
            throw $this->redacted($failure, $doing);
        }
    }

    /**
     * Deliberately without the library exception as its previous one: that one's
     * trace holds the password.
     */
    private function redacted(\Throwable $failure, string $doing): \RuntimeException
    {
        $reason = '' === $this->password ? $failure->getMessage() : str_replace($this->password, '[redacted]', $failure->getMessage());

        return new \RuntimeException(\sprintf('The bank inbox %s:%d could not be %s (%s): %s', $this->host, $this->port, $doing, new \ReflectionClass($failure)->getShortName(), $reason));
    }

    /**
     * Where the user's own folders live, and the delimiter below it: the server's
     * personal namespace, or the top with the inbox's delimiter when it names none.
     *
     * @return array{string, string} the prefix, ending in the delimiter or empty, and the delimiter
     */
    private function home(NamespacedImapConnection $connection, string $inboxDelimiter): array
    {
        [$prefix, $delimiter] = $connection->personalNamespace() ?? ['', ''];
        if ('' === $delimiter) {
            $delimiter = $inboxDelimiter;
        }
        if ('' !== $prefix && !str_ends_with($prefix, $delimiter)) {
            $prefix .= $delimiter;
        }

        return [$prefix, $delimiter];
    }

    private function move(string $uid, string $folder): void
    {
        $message = 1 === preg_match('/^[1-9][0-9]*$/D', $uid) ? $this->fetched[(int) $uid] ?? null : null;
        if (!$message instanceof Message) {
            throw new \LogicException(\sprintf('The message %s was not fetched in this run.', $uid));
        }
        $path = $this->folders[$folder];
        $this->guarded('written to', function () use ($message, $path): void {
            $capabilities = $this->mailbox?->capabilities() ?? [];
            if (\in_array('MOVE', $capabilities, true) || \in_array('UIDPLUS', $capabilities, true)) {
                $message->move($path);
            } else {
                // RFC 3501 alone: copy, then flag the original deleted; the inbox is never expunged.
                $this->mailbox?->connection()->copy($path, $message->uid());
                $message->markDeleted();
            }
        });
        unset($this->fetched[$message->uid()]);
    }

    private function close(): void
    {
        $this->fetched = [];
        $this->folders = [];
        $this->mailbox?->disconnect();
        $this->mailbox = null;
    }
}
