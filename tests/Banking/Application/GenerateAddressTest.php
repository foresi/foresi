<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Banking\Application\AddressRefusal;
use Foresi\Banking\Application\AddressRefused;
use Foresi\Banking\Application\BankFeedDirectory;
use Foresi\Banking\Application\BankFeeds;
use Foresi\Banking\Application\FeedRoute;
use Foresi\Banking\Application\GenerateAddress;
use Foresi\Banking\Domain\BankFeed;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineBankFeedDirectory;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Application\InboundPurposes;
use Foresi\Mail\Application\InboundToken;
use Foresi\Mail\Application\IssueAddresses;
use Foresi\Mail\Application\MailTransaction;
use Foresi\Mail\Domain\InboundAddress;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Application\Sealer;
use Foresi\Shared\Infrastructure\Crypto\SodiumSealer;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Scalars;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * An inbound address is a template with a secret token in it. Only the token's
 * hash and a sealed copy are stored; the address resolves to its feed, and a
 * new token kills the old one at once.
 */
final class GenerateAddressTest extends KernelTestCase
{
    private const string TEMPLATE = 'bank+{token}@example.com';
    private const string IBAN = 'CZ6508000000192000145399';
    /** The feed with the token of its Mail address. */
    private const string FEED_ROW = 'SELECT f.*, a.token_hash, a.token_sealed FROM bank_feed f JOIN mail_inbound_address a ON a.id = f.mail_inbound_address_id';

    private EntityManagerInterface $em;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-02 12:00:00');
    }

    public function testGeneratingStoresOnlyHashAndSealedToken(): void
    {
        $account = $this->account();

        $address = $this->generate()->generate($account->id());
        $this->em->clear();

        $token = $this->addresses()->tokenIn($address);
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[a-z2-7]{26}$/D', $token);
        $row = $this->connection()->fetchAssociative(self::FEED_ROW);
        self::assertIsArray($row);
        self::assertSame(InboundToken::hash($token), $row['token_hash']);
        self::assertSame($token, self::getContainer()->get(Sealer::class)->open(Scalars::text($row['token_sealed'])));
        self::assertSame($account->id(), $row['firm_bank_account_id']);
        self::assertSame(1, Scalars::int($row['generation']));
        // The token is in no column of the feed, and in no audit entry.
        self::assertStringNotContainsString($token, Scalars::text(json_encode($row)));
        self::assertContains(BankFeed::class, $this->connection()->fetchFirstColumn('SELECT entity_class FROM audit_entry'));
        $audit = Scalars::text(json_encode($this->connection()->fetchAllAssociative('SELECT * FROM audit_entry')));
        self::assertStringNotContainsString($token, $audit);
        self::assertStringNotContainsString(Scalars::text($row['token_sealed']), $audit);
    }

    public function testTheFeedIsAuditedUnderItsAccount(): void
    {
        $account = $this->account();

        $this->generate()->generate($account->id());

        $roots = $this->connection()->fetchAllAssociative('SELECT root_class, root_id FROM audit_entry WHERE entity_class = ?', [BankFeed::class]);
        self::assertSame([['root_class' => FirmBankAccount::class, 'root_id' => $account->id()]], $roots);
    }

    public function testTheShownAddressMatchesTheTemplateAndResolves(): void
    {
        $account = $this->account();

        $address = $this->generate()->generate($account->id());
        $this->em->clear();

        self::assertMatchesRegularExpression('/^bank\+[a-z2-7]{26}@example\.com$/D', $address);
        // Mail systems may change the case; the route does not.
        $token = $this->addresses()->tokenIn(strtoupper($address));
        self::assertIsString($token);
        $feedId = $this->connection()->fetchOne('SELECT id FROM bank_feed');
        self::assertEquals(new FeedRoute(Scalars::text($feedId), $account->id()), $this->route(InboundToken::hash($token)));

        $feed = $this->feeds()->ofAccount($account->id());
        self::assertInstanceOf(BankFeed::class, $feed);
        self::assertSame($address, $this->feeds()->revealAddress($feed));
    }

    public function testRegeneratingKillsTheOldToken(): void
    {
        $account = $this->account();
        $old = $this->generate()->generate($account->id());
        $this->clock->modify('+1 hour');

        $new = $this->generate()->generate($account->id());
        $this->em->clear();

        self::assertNotSame($old, $new);
        self::assertNull($this->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($old)))));
        self::assertInstanceOf(FeedRoute::class, $this->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($new)))));
        $row = $this->connection()->fetchAssociative('SELECT generation, rotated_at FROM bank_feed');
        self::assertIsArray($row);
        self::assertSame(2, Scalars::int($row['generation']));
        self::assertStringStartsWith('2026-11-02 13:00:00', Scalars::text($row['rotated_at']));
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    public function testTouchRecordsTheLastMessageAndNeverGoesBack(): void
    {
        $account = $this->account();
        $this->generate()->generate($account->id());
        $feedId = Scalars::text($this->connection()->fetchOne('SELECT id FROM bank_feed'));
        $auditBefore = Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM audit_entry'));

        $this->feeds()->touch($feedId, new \DateTimeImmutable('2026-11-02 14:00:00'));
        $this->feeds()->touch($feedId, new \DateTimeImmutable('2026-11-02 13:00:00'));
        $this->em->clear();

        self::assertStringStartsWith('2026-11-02 14:00:00', Scalars::text($this->connection()->fetchOne('SELECT last_received_at FROM bank_feed')));
        self::assertSame($auditBefore, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM audit_entry')));
    }

    public function testWithoutAnAddressTemplateGeneratingIsRefused(): void
    {
        $account = $this->account();

        foreach (['', 'bank@example.com', 'bank+{token}+{token}@example.com', '{token}'] as $template) {
            try {
                $this->generate($template)->generate($account->id());
                self::fail('A server without an address template handed out an address: '.$template);
            } catch (AddressRefused $refused) {
                self::assertSame(AddressRefusal::NotConfigured, $refused->reason);
            }
        }
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    public function testRefusalComesBeforeLookingAtTheAccount(): void
    {
        $archived = $this->account();
        $archived->archive(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        foreach ([$archived->id(), '01937f4e-0000-7000-8000-0000000000ff'] as $accountId) {
            try {
                $this->generate('')->generate($accountId);
                self::fail('A server without an address template handed out an address.');
            } catch (AddressRefused $refused) {
                self::assertSame(AddressRefusal::NotConfigured, $refused->reason);
            }
        }
    }

    public function testWithoutAKeyGeneratingIsRefused(): void
    {
        $account = $this->account();

        try {
            $this->generate(sealer: new SodiumSealer(''))->generate($account->id());
            self::fail('A server without a key sealed the token of an address.');
        } catch (AddressRefused $refused) {
            self::assertSame(AddressRefusal::NoKey, $refused->reason);
        }
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    public function testAnArchivedAccountGetsNoAddress(): void
    {
        $account = $this->account();
        $account->archive(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        $this->expectExceptionObject(new AddressRefused(AddressRefusal::Archived));
        $this->generate()->generate($account->id());
    }

    public function testTheAddressOfAnArchivedAccountNoLongerRoutes(): void
    {
        $account = $this->account();
        $token = InboundToken::hash(Scalars::text($this->addresses()->tokenIn($this->generate()->generate($account->id()))));
        self::assertInstanceOf(FeedRoute::class, $this->route($token));

        $account->archive(new \DateTimeImmutable('2026-11-02'));
        $this->em->flush();

        self::assertNull($this->route($token), 'mail to an archived account is not taken in');
    }

    public function testTokensAreFreshBase32(): void
    {
        $tokens = [];
        for ($i = 0; $i < 200; ++$i) {
            $tokens[] = InboundToken::generate();
        }

        self::assertCount(200, array_unique($tokens));
        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('/^[a-z2-7]{26}$/D', $token);
        }
        // Every symbol of the alphabet turns up, so no bit is stuck.
        self::assertCount(32, array_unique(str_split(implode('', $tokens))));
    }

    public function testTokenInMatchesOnlyTheTemplate(): void
    {
        $addresses = $this->addresses();
        $token = str_repeat('a', 25).'7';

        self::assertSame($token, $addresses->tokenIn('bank+'.$token.'@example.com'));
        self::assertSame($token, $addresses->tokenIn(' <BANK+'.strtoupper($token).'@Example.COM> '));
        self::assertNull($addresses->tokenIn('bank+'.$token.'@example.org'));
        self::assertNull($addresses->tokenIn('xbank+'.$token.'@example.com'));
        self::assertNull($addresses->tokenIn('bank+'.$token.'1@example.com'));
        self::assertNull($addresses->tokenIn('bank+'.substr($token, 1).'@example.com'));
        self::assertNull($addresses->tokenIn('bank+'.str_repeat('a', 25).'8@example.com'));
        self::assertNull($addresses->tokenIn('bankx'.$token.'@example.com'));
        self::assertNull(new InboundAddresses('')->tokenIn('bank+'.$token.'@example.com'));
        self::assertFalse(new InboundAddresses('')->isConfigured());
        self::assertTrue($addresses->isConfigured());
    }

    private function account(): FirmBankAccount
    {
        $account = FirmAccounts::add($this->em, self::IBAN);
        $this->em->flush();

        return $account;
    }

    private function generate(string $template = self::TEMPLATE, ?Sealer $sealer = null): GenerateAddress
    {
        $container = self::getContainer();

        return new GenerateAddress(
            $container->get(FirmBankAccounts::class),
            $this->feeds(),
            new IssueAddresses(new InboundAddresses($template), $this->store(), $container->get(InboundPurposes::class), $sealer ?? $container->get(Sealer::class), $container->get(MailTransaction::class), $this->clock),
            $container->get(TransactionalWork::class),
            $this->clock,
        );
    }

    private function addresses(): InboundAddresses
    {
        return new InboundAddresses(self::TEMPLATE);
    }

    private function feeds(): BankFeeds
    {
        return self::getContainer()->get(BankFeeds::class);
    }

    /**
     * The feed mail with this token hash goes to, as Mail routes it to the feed's address.
     */
    private function route(string $tokenHash): ?FeedRoute
    {
        $address = $this->store()->routable($tokenHash);

        return $address instanceof InboundAddress ? $this->directory()->route($address->id()) : null;
    }

    private function store(): InboundAddressStore
    {
        return self::getContainer()->get(InboundAddressStore::class);
    }

    private function directory(): BankFeedDirectory
    {
        // Nothing in this task asks for it yet, so the container may have dropped the service.
        return new DoctrineBankFeedDirectory($this->connection());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
