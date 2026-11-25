<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Instance\Application\ConfiguredZone;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\ZoneFallback;
use Foresi\Mail\Application\InboundFiles;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Banking\Support\Scalars;
use Foresi\Tests\Support\Mail\FailingInboundFiles;
use Foresi\Tests\Support\Mail\LimitedReceive;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Inbound mail is routed by the token in its recipient, stored as a file and a
 * row, and handed to the purpose of its address. The same message twice to one
 * address is one row, and nothing of an unknown recipient is kept.
 */
final class ReceiveMailTest extends KernelTestCase
{
    private MailKit $kit;
    private ReceiveMail $receive;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $container = self::getContainer();
        $this->kit = MailKit::seed($container->get(EntityManagerInterface::class), $container->get(AddressIssuer::class));
        $this->receive = $container->get(ReceiveMail::class);
        $this->connection = $container->get(Connection::class);
    }

    protected function tearDown(): void
    {
        RecordingPurpose::reset();
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAKnownTokenStoresFileAndRowAndHandsItToThePurpose(): void
    {
        $raw = MailKit::mail($this->kit->address, headers: ['In-Reply-To' => '<earlier@mail.example.com>', 'References' => '<first@mail.example.com> <earlier@mail.example.com>']);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Webhook));

        $row = $this->onlyRow();
        self::assertSame($this->kit->addressId, $row['address_id']);
        self::assertSame('webhook', $row['channel']);
        self::assertSame(hash('sha256', MailKit::MESSAGE_ID), $row['dedup_key']);
        self::assertSame(hash('sha256', $raw), $row['raw_sha256']);
        self::assertSame(\strlen($raw), Scalars::int($row['size']));
        self::assertSame(MailKit::MESSAGE_ID, $row['message_id_header']);
        self::assertSame('earlier@mail.example.com', $row['in_reply_to']);
        self::assertSame('first@mail.example.com earlier@mail.example.com', $row['references_header']);
        self::assertSame('info@bank.example.com', $row['from_address']);
        self::assertSame('Payment notification', $row['subject']);
        self::assertStringStartsWith('2026-11-20 09:00:00', Scalars::text($row['sent_at']));
        self::assertStringStartsWith('2026-11-20 12:00:00', Scalars::text($row['received_at']));
        // No receiving server left results: the sender is not known.
        self::assertSame(['unknown', 'no-results'], [$row['verdict'], $row['verdict_basis']]);

        $id = Scalars::text($row['id']);
        self::assertSame($id.'.eml', $row['raw_key']);
        $stored = self::getContainer()->get(InboundFiles::class)->read($id, Scalars::text($row['raw_key']), hash('sha256', $raw));
        self::assertSame($raw, $stored);

        self::assertSame([[$id, $this->kit->addressId]], RecordingPurpose::accepted());
    }

    public function testTheTokenIsFoundInDeliveredToWhenToIsTheOwnersMailbox(): void
    {
        // A firm's own mailbox forwards the bank's mail: To still names the firm.
        $raw = MailKit::mail('Example Firm <accounts@firm.example.com>', headers: [
            'Delivered-To' => $this->kit->address,
            'X-Forwarded-To' => 'someone@example.com',
        ]);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Imap));

        $row = $this->onlyRow();
        self::assertSame($this->kit->addressId, $row['address_id']);
        self::assertSame('imap', $row['channel']);
    }

    public function testTheTokenIsFoundAmongSeveralRecipientsAndInAnyCase(): void
    {
        $raw = MailKit::mail('accounts@firm.example.com, "Bank feed" <'.strtoupper($this->kit->address).'>');

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Imap));
        self::assertSame($this->kit->addressId, $this->onlyRow()['address_id']);
    }

    public function testTheRecipientOfTheWebhookComesFirst(): void
    {
        $raw = MailKit::mail('accounts@firm.example.com', headers: ['Delivered-To' => $this->kit->unknownAddress()]);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Webhook, $this->kit->address));
        self::assertSame($this->kit->addressId, $this->onlyRow()['address_id']);
    }

    public function testAnUnknownTokenStoresNothing(): void
    {
        // Marked with the token of this test, so a leaked file is told from those of other test processes.
        $marked = ['X-Test-Run' => $this->kit->token];
        $raw = MailKit::mail($this->kit->unknownAddress(), headers: $marked);

        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive($raw, InboundChannel::Webhook));
        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive(MailKit::mail('accounts@firm.example.com', headers: $marked), InboundChannel::Imap));

        self::assertSame(0, $this->rowCount());
        self::assertSame([], RecordingPurpose::accepted());
        self::assertSame([], $this->storedFiles());
    }

    public function testTheSameMessageTwiceIsOneRow(): void
    {
        $fetched = MailKit::mail($this->kit->address);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($fetched, InboundChannel::Imap));
        self::assertSame(ReceiveOutcome::Duplicate, $this->receive->receive($fetched, InboundChannel::Webhook));

        self::assertSame(1, $this->rowCount());
        self::assertSame('imap', $this->onlyRow()['channel']);
        self::assertCount(1, RecordingPurpose::accepted());
        self::assertCount(1, $this->storedFiles());
    }

    /**
     * A Message-ID is the sender's word: anyone can send junk with the one of a
     * notice the bank is about to send. A copy with the same Message-ID and other
     * bytes is kept under the hash of its bytes; should both carry the same
     * movement, the movement of the second is a possible duplicate.
     */
    public function testTheSameMessageIdWithOtherBytesIsKeptUnderItsBytes(): void
    {
        $first = MailKit::mail($this->kit->address, body: "Junk that took the bank's Message-ID.\r\n");
        // The webhook copy passed one more server, so its bytes differ.
        $second = MailKit::mail($this->kit->address, headers: ['Received' => 'from relay.example.com by mx.example.com']);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($first, InboundChannel::Imap));
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($second, InboundChannel::Webhook));
        self::assertSame(ReceiveOutcome::Duplicate, $this->receive->receive($second, InboundChannel::Imap));

        $keys = $this->connection->fetchFirstColumn('SELECT dedup_key FROM mail_message ORDER BY dedup_key');
        $expected = [hash('sha256', MailKit::MESSAGE_ID), hash('sha256', $second)];
        sort($expected);
        self::assertSame($expected, $keys);
        self::assertCount(2, RecordingPurpose::accepted());
    }

    public function testNoMessageIdFallsBackToTheRawHash(): void
    {
        $raw = MailKit::mail($this->kit->address, null);
        $other = MailKit::mail($this->kit->address, null, body: "Another payment.\r\n");

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Imap));
        self::assertSame(ReceiveOutcome::Duplicate, $this->receive->receive($raw, InboundChannel::Webhook));
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($other, InboundChannel::Imap));

        $keys = $this->connection->fetchFirstColumn('SELECT dedup_key FROM mail_message ORDER BY dedup_key');
        $expected = [hash('sha256', $raw), hash('sha256', $other)];
        sort($expected);
        self::assertSame($expected, $keys);
    }

    public function testA25MbMessageIsAcceptedAndALargerOneRefused(): void
    {
        $header = MailKit::mail($this->kit->address, body: '');
        // A text part padded to the size, in lines as a real mail has.
        $line = str_repeat('a', 76)."\r\n";
        $padding = ReceiveMail::MAX_BYTES - \strlen($header);
        $atLimit = $header.str_repeat($line, intdiv($padding, \strlen($line))).str_repeat('a', $padding % \strlen($line));
        self::assertSame(ReceiveMail::MAX_BYTES, \strlen($atLimit));

        self::assertSame(ReceiveOutcome::TooLarge, $this->receive->receive($atLimit.'a', InboundChannel::Webhook));
        self::assertSame(0, $this->rowCount());
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($atLimit, InboundChannel::Webhook));
        self::assertSame(1, $this->rowCount());
        self::assertSame(ReceiveMail::MAX_BYTES, Scalars::int($this->connection->fetchOne('SELECT size FROM mail_message')));
    }

    /**
     * The count is of the instance day, not the UTC day: mails at 21:30 UTC are
     * 22:30 in Prague and count to November 20, while 23:30 UTC is already
     * 00:30 of November 21 there.
     */
    public function testTheDailyLimitCountsPerInstanceDay(): void
    {
        $limited = LimitedReceive::of(static fn (string $id): mixed => self::getContainer()->get($id), 2, $this->prague());
        $receive = static fn (string $address, string $id): ReceiveOutcome => $limited->receive->receive(MailKit::mail($address, $id), InboundChannel::Webhook);

        $files = static function (): int {
            $found = glob(self::getContainer()->getParameter('app.inbound_mail_dir').'/*.eml');

            return false === $found ? 0 : \count($found);
        };
        $before = $files();
        Clock::set(new MockClock('2026-11-20 21:30:00', 'UTC'));
        self::assertSame(ReceiveOutcome::Stored, $receive($this->kit->address, 'a1@mail.example.com'));
        self::assertSame(ReceiveOutcome::Stored, $receive($this->kit->address, 'a2@mail.example.com'));
        self::assertSame(ReceiveOutcome::OverLimit, $receive($this->kit->address, 'a3@mail.example.com'));
        // Nothing of the dropped mail is kept: no row and no file.
        self::assertSame(2, $this->rowCount());
        self::assertSame($before + 2, $files());
        // A copy of a stored mail after the limit is dropped too, and takes no place.
        self::assertSame(ReceiveOutcome::OverLimit, $receive($this->kit->address, 'a1@mail.example.com'));
        self::assertSame(2, $this->rowCount());

        // Past midnight in Prague, still the same UTC day: a new instance day.
        Clock::set(new MockClock('2026-11-20 23:30:00', 'UTC'));
        self::assertSame(ReceiveOutcome::Stored, $receive($this->kit->address, 'b1@mail.example.com'));
        self::assertSame(ReceiveOutcome::Stored, $receive($this->kit->address, 'b2@mail.example.com'));
        self::assertSame(ReceiveOutcome::OverLimit, $receive($this->kit->address, 'b3@mail.example.com'));
        self::assertSame(4, $this->rowCount());
        self::assertStringStartsWith('2026-11-20 23:30:00', Scalars::text($this->connection->fetchOne('SELECT MAX(received_at) FROM mail_message')));

        // The limit is per address: another address takes its own.
        $other = self::getContainer()->get(AddressIssuer::class)->issue(RecordingPurpose::KEY, null, 'Other');
        self::assertSame(ReceiveOutcome::Stored, $receive($other->address, 'c1@mail.example.com'));
    }

    private function prague(): InstanceClock
    {
        return new InstanceClock(new ConfiguredZone('Europe/Prague'), new class implements ZoneFallback {
            public function zone(): ?\DateTimeZone
            {
                return null;
            }
        }, new Clock());
    }

    public function testBytesThatAreNoMailAreUnreadable(): void
    {
        self::assertSame(ReceiveOutcome::Unreadable, $this->receive->receive("not a mail\r\n", InboundChannel::Webhook));
        self::assertSame(ReceiveOutcome::Unreadable, $this->receive->receive('', InboundChannel::Imap));
        self::assertSame(0, $this->rowCount());
    }

    public function testTheAddressRecordsLastReceived(): void
    {
        self::assertNull($this->connection->fetchOne('SELECT last_received_at FROM mail_inbound_address'));

        $this->receive->receive(MailKit::mail($this->kit->address), InboundChannel::Imap);

        self::assertStringStartsWith('2026-11-20 12:00:00', Scalars::text($this->connection->fetchOne('SELECT last_received_at FROM mail_inbound_address')));
    }

    public function testAFullDiskStoresNoRow(): void
    {
        FailingInboundFiles::failOn('fail-storage');

        try {
            $this->receive->receive(MailKit::mail($this->kit->address, headers: ['X-Test' => 'fail-storage']), InboundChannel::Imap);
            self::fail('A full disk fails the store.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The disk is full.', $failure->getMessage());
        } finally {
            FailingInboundFiles::failOn(null);
        }

        self::assertSame(0, $this->rowCount());
    }

    public function testAFailedStoreLeavesNoFileBehind(): void
    {
        // The duplicate check still works; only the insert, after the file is written, fails.
        $this->connection->executeStatement('ALTER TABLE mail_message DROP COLUMN subject');
        // Marked, so a file another test process writes to the shared directory meanwhile does not count.
        $marker = bin2hex(random_bytes(8));

        try {
            $this->receive->receive(MailKit::mail($this->kit->address, headers: ['X-Test-Run' => $marker]), InboundChannel::Imap);
            self::fail('Storing without the column must fail.');
        } catch (\Throwable $failure) {
            self::assertStringNotContainsString('must fail', $failure->getMessage());
        }

        $directory = self::getContainer()->getParameter('app.inbound_mail_dir');
        self::assertSame([], array_filter($this->storedFiles(), static fn (string $name): bool => str_contains((string) @file_get_contents($directory.'/'.$name), $marker)));
        self::assertSame([], RecordingPurpose::accepted());
    }

    public function testAPurposeThatThrowsLeavesNoRowAndNoFile(): void
    {
        $marker = bin2hex(random_bytes(8));
        RecordingPurpose::failWith(new \RuntimeException('The purpose failed.'));

        try {
            $this->receive->receive(MailKit::mail($this->kit->address, headers: ['X-Test-Run' => $marker]), InboundChannel::Webhook);
            self::fail('The failure of the purpose travels on.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The purpose failed.', $failure->getMessage());
        }

        self::assertSame(0, $this->rowCount());
        self::assertSame([], $this->filesWith($marker));
        self::assertNull($this->connection->fetchOne('SELECT last_received_at FROM mail_inbound_address'));
    }

    public function testAMessageThePurposeRefusesIsTreatedAsAnUnknownRecipient(): void
    {
        $marker = bin2hex(random_bytes(8));
        RecordingPurpose::failWith(RecordingPurpose::refusal());

        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive(MailKit::mail($this->kit->address, headers: ['X-Test-Run' => $marker]), InboundChannel::Webhook));

        self::assertSame(0, $this->rowCount());
        self::assertSame([], $this->filesWith($marker));
    }

    public function testAnUnknownPurposeKeyDropsTheMailUnread(): void
    {
        // An address whose purpose no installed module registered, such as one of a module removed since.
        $token = 'zyxwvutsrqponmlkjihgfedcba';
        $this->connection->insert('mail_inbound_address', [
            'id' => Id::generate(), 'purpose' => 'removed_module', 'owner_user_id' => null, 'label' => 'Gone', 'token_hash' => hash('sha256', $token),
            'token_sealed' => null, 'created_at' => '2026-11-01 10:00:00', 'revoked_at' => null, 'last_received_at' => null,
        ]);
        $marker = bin2hex(random_bytes(8));

        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive(MailKit::mail('bank+'.$token.'@example.com', headers: ['X-Test-Run' => $marker]), InboundChannel::Webhook));

        self::assertSame(0, $this->rowCount());
        self::assertSame([], RecordingPurpose::accepted());
        self::assertSame([], $this->filesWith($marker));
    }

    public function testARevokedAddressTakesInNothing(): void
    {
        self::getContainer()->get(AddressIssuer::class)->revoke($this->kit->addressId);

        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive(MailKit::mail($this->kit->address), InboundChannel::Webhook));
        self::assertSame(0, $this->rowCount());
    }

    public function testTheSameMessageToTwoAddressesIsARowForEach(): void
    {
        $other = self::getContainer()->get(AddressIssuer::class)->issue(RecordingPurpose::KEY, null, 'Other');

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive(MailKit::mail($this->kit->address), InboundChannel::Imap));
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive(MailKit::mail($other->address), InboundChannel::Imap));

        self::assertSame(2, $this->rowCount());
    }

    /**
     * The mail files stored since the test began that carry the marker.
     *
     * @return list<string>
     */
    private function filesWith(string $marker): array
    {
        $directory = self::getContainer()->getParameter('app.inbound_mail_dir');

        return array_values(array_filter($this->storedFiles(), static fn (string $name): bool => str_contains((string) @file_get_contents($directory.'/'.$name), $marker)));
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRow(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM mail_message');
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function rowCount(): int
    {
        return Scalars::int($this->connection->fetchOne('SELECT COUNT(*) FROM mail_message'));
    }

    /**
     * The mail files stored since the test began.
     *
     * @return list<string>
     */
    private function storedFiles(): array
    {
        $directory = self::getContainer()->getParameter('app.inbound_mail_dir');
        $names = is_dir($directory) ? scandir($directory) : false;
        if (false === $names) {
            return [];
        }

        // ParaTest runs other banking tests in the same directory at the same time, so the files are the ones that
        // carry the random token of this test's seeded feed, in an address or in a marker header.
        $own = function (string $name) use ($directory): bool {
            $raw = str_ends_with($name, '.eml') ? @file_get_contents($directory.'/'.$name) : false;

            return \is_string($raw) && (false !== stripos($raw, $this->kit->token));
        };

        return array_values(array_filter($names, $own));
    }
}
