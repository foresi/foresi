<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Banking\Support\Scalars;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A mail the purpose took records the thin event mail.message.received in the
 * same transaction; one that is refused, fails or is a duplicate records none.
 */
final class MailMessageReceivedEventTest extends KernelTestCase
{
    private MailKit $kit;
    private ReceiveMail $receive;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->kit = MailKit::seed($container->get(EntityManagerInterface::class), $container->get(AddressIssuer::class));
        $this->receive = $container->get(ReceiveMail::class);
        $this->connection = $container->get(Connection::class);
    }

    protected function tearDown(): void
    {
        RecordingPurpose::reset();
        parent::tearDown();
    }

    public function testTheReceivedEventCarriesIdsOnly(): void
    {
        $raw = MailKit::mail($this->kit->address);

        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Webhook));

        $messageId = Scalars::text($this->connection->fetchOne('SELECT id FROM mail_message'));
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM event_log WHERE name = 'mail.message.received'");
        self::assertCount(1, $rows);
        $data = json_decode(Scalars::text($rows[0]['data']), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $keys = array_keys($data);
        sort($keys);
        self::assertSame(['message_id', 'purpose'], $keys);
        self::assertSame([$messageId, RecordingPurpose::KEY], [$data['message_id'], $data['purpose']]);
        self::assertSame(['mail_message', $messageId], [$rows[0]['subject_type'], $rows[0]['subject_id']]);
        // Nothing of the mail itself is in the stored row.
        $stored = json_encode($rows[0], \JSON_THROW_ON_ERROR);
        foreach (['info@bank.example.com', 'Payment notification', 'A payment arrived', $this->kit->address, $this->kit->token] as $text) {
            self::assertStringNotContainsString($text, $stored);
        }
    }

    public function testARefusedMailRecordsNoEvent(): void
    {
        RecordingPurpose::failWith(RecordingPurpose::refusal());

        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive->receive(MailKit::mail($this->kit->address), InboundChannel::Webhook));

        self::assertSame(0, $this->events());
        self::assertSame(0, Scalars::int($this->connection->fetchOne('SELECT COUNT(*) FROM mail_message')));
    }

    public function testAPurposeThatFailsRecordsNoEvent(): void
    {
        RecordingPurpose::failWith(new \RuntimeException('The purpose broke.'));

        try {
            $this->receive->receive(MailKit::mail($this->kit->address), InboundChannel::Webhook);
            self::fail('The failure is not swallowed.');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->events());
    }

    public function testADuplicateRecordsNoSecondEvent(): void
    {
        $raw = MailKit::mail($this->kit->address);

        $this->receive->receive($raw, InboundChannel::Webhook);
        self::assertSame(ReceiveOutcome::Duplicate, $this->receive->receive($raw, InboundChannel::Imap));

        self::assertSame(1, $this->events());
    }

    private function events(): int
    {
        return Scalars::int($this->connection->fetchOne("SELECT COUNT(*) FROM event_log WHERE name = 'mail.message.received'"));
    }
}
