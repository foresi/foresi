<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Banking\Application\BankFeeds;
use Foresi\Banking\Application\GenerateAddress;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Application\TrustVerdict;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Domain\BankFeed;
use Foresi\Banking\Migrations\Version20261124160001;
use Foresi\Banking\Migrations\Version20261124160002;
use Foresi\Banking\Migrations\Version20261124160003;
use Foresi\Mail\Application\InboundFiles;
use Foresi\Mail\Application\InboundToken;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\Sealer;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Mail\SyntheticMail;
use Foresi\Tests\Support\Scalars;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The bank feeds and the bank mail stored before the Mail module existed move
 * into it with their ids, so the raw files, the movements and the addresses a
 * bank already sends to keep working; going down gives back the same rows.
 */
final class BankingMailMigrationTest extends KernelTestCase
{
    private const string FEED = '01937f4e-0000-7000-8000-0000000000f1';
    private const string MESSAGE = '01937f4e-0000-7000-8000-0000000000a1';
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyz';
    private const array STEPS = [Version20261124160001::class, Version20261124160002::class, Version20261124160003::class];

    private EntityManagerInterface $em;
    private string $raw;
    private FirmBankAccount $account;

    protected function setUp(): void
    {
        self::bootKernel();
        Clock::set(new MockClock('2026-11-25 10:00:00', 'UTC'));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        // The tables as they were before the move.
        foreach (array_reverse(self::STEPS) as $step) {
            $this->migrate($step, 'down');
        }
        $this->seed();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testUpKeepsTheRawBytesTheSubjectAndTheStatus(): void
    {
        $this->up();

        $messages = self::getContainer()->get(Messages::class);
        self::assertSame($this->raw, $messages->raw(self::MESSAGE));
        $view = $messages->find(self::MESSAGE);
        self::assertNotNull($view);
        self::assertSame(['Payment notification', 'info@bank.example.com', 'webhook', self::FEED, 'bank_feed', 'unknown'], [$view->subject, $view->from, $view->channel, $view->addressId, $view->purpose, $view->verdict]);
        self::assertStringStartsWith('2026-11-20 12:00:00', $view->receivedAt->format('Y-m-d H:i:s'));

        $row = $this->connection()->fetchAssociative('SELECT * FROM inbound_message');
        self::assertIsArray($row);
        self::assertSame([self::MESSAGE, self::MESSAGE, self::FEED, 'processed', 'verified', 'dmarc', 'cz.kb', 2], [$row['id'], $row['mail_message_id'], $row['bank_feed_id'], $row['status'], $row['trust'], $row['trust_basis'], $row['parser_id'], Scalars::int($row['attempts'])]);
        self::assertSame([self::MESSAGE], $this->connection()->fetchFirstColumn('SELECT inbound_message_id FROM bank_movement'));
        // The Messages tab lists it as before.
        $listed = self::getContainer()->get(InboundMessages::class)->row(self::MESSAGE, new \DateTimeImmutable('2026-11-25 09:00:00'));
        self::assertSame(['Payment notification', 'info@bank.example.com'], [$listed?->subject, $listed?->from]);

        $address = $this->connection()->fetchAssociative('SELECT * FROM mail_inbound_address');
        self::assertIsArray($address);
        self::assertSame([self::FEED, 'bank_feed', InboundToken::hash(self::TOKEN), null], [$address['id'], $address['purpose'], $address['token_hash'], $address['revoked_at']]);
        self::assertStringStartsWith('2026-11-20 12:00:00', Scalars::text($address['last_received_at']));
        self::assertSame(self::FEED, $this->connection()->fetchOne('SELECT mail_inbound_address_id FROM bank_feed'));
    }

    public function testAnExistingBankFeedTokenStillRoutesAfterTheMove(): void
    {
        $this->up();

        $notice = SyntheticMail::plain('bank+'.self::TOKEN.'@example.com', 'after-the-move@mail.example.com');
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive($notice, InboundChannel::Imap));

        self::assertSame(2, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM inbound_message WHERE bank_feed_id = ?', [self::FEED])));
        $id = Scalars::text($this->connection()->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ?', [hash('sha256', $notice)]));
        self::assertSame([$id, 'received'], array_values((array) $this->connection()->fetchAssociative('SELECT mail_message_id, status FROM inbound_message WHERE id = ?', [$id])));
        // The firm still reads the address it gave its bank.
        $feed = self::getContainer()->get(BankFeeds::class)->ofAccount($this->account->id());
        self::assertInstanceOf(BankFeed::class, $feed);
        self::assertSame('bank+'.self::TOKEN.'@example.com', self::getContainer()->get(BankFeeds::class)->revealAddress($feed));
    }

    public function testDownGivesBackTheSameRows(): void
    {
        $message = $this->connection()->fetchAssociative('SELECT * FROM inbound_message');
        $feed = $this->connection()->fetchAssociative('SELECT * FROM bank_feed');
        $this->up();

        foreach (array_reverse(self::STEPS) as $step) {
            $this->migrate($step, 'down');
        }

        self::assertEquals($message, $this->connection()->fetchAssociative('SELECT * FROM inbound_message'));
        self::assertEquals($feed, $this->connection()->fetchAssociative('SELECT * FROM bank_feed'));
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_message')));
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_inbound_address')));
        self::assertSame([self::MESSAGE], $this->connection()->fetchFirstColumn('SELECT inbound_message_id FROM bank_movement'));
    }

    /**
     * Mail dedups per address, the bank tables did across all feeds: a mail that
     * reached two feeds after the move gets a key per row on the way down, so
     * going back never stops half-way.
     */
    public function testDownSucceedsAfterOneMailReachedTwoFeeds(): void
    {
        $this->up();
        $second = FirmAccounts::add($this->em, 'CZ5508000000001234567899');
        $this->em->flush();
        $other = self::getContainer()->get(GenerateAddress::class)->generate($second->id());
        $receive = self::getContainer()->get(ReceiveMail::class);
        $first = 'bank+'.self::TOKEN.'@example.com';
        // One notice forwarded to both feeds, and the same bytes without a Message-ID to both again.
        $notice = SyntheticMail::plain($first, 'to-both@mail.example.com');
        $plain = SyntheticMail::plain($first, null, body: "Another payment.\r\n");
        foreach ([$notice, $plain] as $raw) {
            self::assertSame(ReceiveOutcome::Stored, $receive->receive($raw, InboundChannel::Webhook));
            self::assertSame(ReceiveOutcome::Stored, $receive->receive($raw, InboundChannel::Webhook, $other));
        }
        self::assertSame(5, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM inbound_message')));

        foreach (array_reverse(self::STEPS) as $step) {
            $this->migrate($step, 'down');
        }

        $keys = array_map(Scalars::text(...), $this->connection()->fetchFirstColumn('SELECT dedup_key FROM inbound_message'));
        self::assertCount(5, $keys);
        self::assertCount(5, array_unique($keys), 'every row has a key of its own');
        self::assertContains(hash('sha256', 'to-both@mail.example.com'), $keys, 'the earlier copy keeps its key');
        self::assertContains(hash('sha256', $notice), $keys, 'the later copy takes the hash of its bytes');
        self::assertContains(hash('sha256', $plain), $keys);
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_message')));
    }

    /**
     * A firm with an account, its feed, a processed notice with its raw file and
     * the movement it recorded, as the bank tables held them before the move.
     */
    private function seed(): void
    {
        $this->em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->account = FirmAccounts::add($this->em, 'CZ6508000000192000145399');
        $this->em->flush();

        $container = self::getContainer();
        $this->connection()->insert('bank_feed', [
            'id' => self::FEED, 'firm_bank_account_id' => $this->account->id(), 'token_hash' => InboundToken::hash(self::TOKEN),
            'token_sealed' => $container->get(Sealer::class)->seal(self::TOKEN), 'generation' => 1, 'rotated_at' => null,
            'last_received_at' => '2026-11-20 12:00:00', 'created_at' => '2026-11-19 09:00:00',
        ]);
        $this->raw = SyntheticMail::plain('bank+'.self::TOKEN.'@example.com', 'before-the-move@mail.example.com');
        $key = $container->get(InboundFiles::class)->store(self::MESSAGE, $this->raw);
        $this->connection()->insert('inbound_message', [
            'id' => self::MESSAGE, 'bank_feed_id' => self::FEED, 'channel' => 'webhook', 'dedup_key' => hash('sha256', 'before-the-move@mail.example.com'),
            'raw_key' => $key, 'raw_sha256' => hash('sha256', $this->raw), 'size' => \strlen($this->raw), 'from_address' => 'info@bank.example.com',
            'subject' => 'Payment notification', 'received_at' => '2026-11-20 12:00:00', 'status' => 'processed', 'trust' => 'verified', 'trust_basis' => 'dmarc',
            'parser_id' => 'cz.kb', 'parser_version' => 1, 'failure' => null, 'failure_detail' => null, 'attempts' => 2, 'processed_at' => '2026-11-20 12:00:05',
        ]);
        $container->get(RecordMovement::class)->record(
            new MovementInput($this->account->id(), self::MESSAGE, MovementDirection::Incoming, Money::of('150.00', Currency::CZK), new \DateTimeImmutable('2026-11-20'), null, null, 'Example Buyer Ltd', '2026001', null, null, null, null),
            new TrustVerdict(MovementTrust::Verified, TrustVerdict::DMARC),
        );
        $this->em->flush();
        $this->em->clear();
    }

    private function up(): void
    {
        foreach (self::STEPS as $step) {
            $this->migrate($step, 'up');
        }
        $this->em->clear();
    }

    /**
     * Runs one step as Doctrine runs it: the statements it adds first, then what it
     * changes in the schema.
     *
     * @param class-string<AbstractMigration> $version
     * @param 'up'|'down'                     $direction
     */
    private function migrate(string $version, string $direction): void
    {
        $manager = $this->connection()->createSchemaManager();
        $from = $manager->introspectSchema();
        $to = clone $from;
        $migration = $this->migration($version);
        'up' === $direction ? $migration->up($to) : $migration->down($to);
        foreach ($migration->getSql() as $query) {
            $this->connection()->executeStatement($query->getStatement(), array_values($query->getParameters()));
        }
        foreach ($this->connection()->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($from, $to)) as $sql) {
            $this->connection()->executeStatement($sql);
        }
        'up' === $direction ? $migration->postUp($to) : $migration->postDown($to);
    }

    /**
     * Loaded by Doctrine from the module's migrations directory, as a deploy loads it.
     *
     * @param class-string<AbstractMigration> $version
     */
    private function migration(string $version): AbstractMigration
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $class = $factory->getMigrationRepository()->getMigration(new Version($version))->getMigration()::class;

        return new $class($this->connection(), $factory->getLogger());
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
