<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\AddressRefusal;
use App\Banking\Application\AddressRefused;
use App\Banking\Application\BankFeedDirectory;
use App\Banking\Application\BankFeeds;
use App\Banking\Application\FeedRoute;
use App\Banking\Application\GenerateAddress;
use App\Banking\Application\InboundAddresses;
use App\Banking\Application\InboundToken;
use App\Banking\Domain\BankFeed;
use App\Banking\Infrastructure\Doctrine\DoctrineBankFeedDirectory;
use App\Invoicing\Application\MailSecrets;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownFirmBankAccount;
use App\Party\Domain\Address;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Doctrine\TenantFilterBypass;
use App\Tests\Banking\Support\Scalars;
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * An inbound address is a template with a secret token in it. Only the token's
 * hash and a sealed copy are stored; the address resolves to its feed across
 * tenants, and a new token kills the old one at once.
 */
final class GenerateAddressTest extends KernelTestCase
{
    private const string TEMPLATE = 'bank+{token}@example.com';
    private const string IBAN = 'CZ6508000000192000145399';

    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;
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
        $this->context = $container->get(TenantContext::class);
        $this->tenant = TenantId::generate();
        $this->context->switchTo($this->tenant);
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
        $row = $this->connection()->fetchAssociative('SELECT * FROM bank_feed');
        self::assertIsArray($row);
        self::assertSame(InboundToken::hash($token), $row['token_hash']);
        self::assertSame($token, self::getContainer()->get(MailSecrets::class)->open(Scalars::text($row['token_sealed'])));
        self::assertSame($account->id(), $row['firm_bank_account_id']);
        self::assertSame(1, Scalars::int($row['generation']));
        // The token is in no column of the feed, and in no audit entry.
        self::assertStringNotContainsString($token, Scalars::text(json_encode($row)));
        self::assertContains(BankFeed::class, $this->connection()->fetchFirstColumn('SELECT entity_class FROM audit_entry'));
        $audit = Scalars::text(json_encode($this->connection()->fetchAllAssociative('SELECT * FROM audit_entry')));
        self::assertStringNotContainsString($token, $audit);
        self::assertStringNotContainsString(Scalars::text($row['token_sealed']), $audit);
    }

    public function testTheFeedIsAuditedUnderItsFirm(): void
    {
        $account = $this->account();

        $this->generate()->generate($account->id());

        $roots = $this->connection()->fetchAllAssociative('SELECT root_class, root_id FROM audit_entry WHERE entity_class = ?', [BankFeed::class]);
        self::assertSame([['root_class' => LegalEntity::class, 'root_id' => $account->firm()->id()]], $roots);
    }

    public function testTheShownAddressMatchesTheTemplateAndResolves(): void
    {
        $account = $this->account();

        $address = $this->generate()->generate($account->id());
        $this->em->clear();
        $this->context->reset();

        self::assertMatchesRegularExpression('/^bank\+[a-z2-7]{26}@example\.com$/D', $address);
        // Mail systems may change the case; the route does not.
        $token = $this->addresses()->tokenIn(strtoupper($address));
        self::assertIsString($token);
        $feedId = $this->connection()->fetchOne('SELECT id FROM bank_feed');
        $firmId = $account->firm()->id();
        self::assertEquals(new FeedRoute($this->tenant->toString(), Scalars::text($feedId), $account->id(), $firmId), $this->directory()->route(InboundToken::hash($token)));

        $this->context->switchTo($this->tenant);
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
        self::assertNull($this->directory()->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($old)))));
        self::assertInstanceOf(FeedRoute::class, $this->directory()->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($new)))));
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
        self::assertInstanceOf(FeedRoute::class, $this->directory()->route($token));

        $account->archive(new \DateTimeImmutable('2026-11-02'));
        $this->em->flush();

        self::assertNull($this->directory()->route($token), 'mail to an archived account is not taken in');
    }

    public function testAFeedRoutesOnlyToAnAccountOfItsOwnTenant(): void
    {
        $account = $this->account();
        $token = InboundToken::hash(Scalars::text($this->addresses()->tokenIn($this->generate()->generate($account->id()))));

        $this->connection()->update('firm_bank_account', ['tenant_id' => TenantId::generate()->toString()], ['id' => $account->id()]);

        self::assertNull($this->directory()->route($token));
    }

    public function testAnotherTenantsAccountIsNotFound(): void
    {
        $account = $this->account();
        $this->context->switchTo(TenantId::generate());

        $this->expectException(UnknownFirmBankAccount::class);
        $this->generate()->generate($account->id());
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
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($firm);
        $account = FirmAccounts::add($this->em, $firm, self::IBAN);
        $this->em->flush();

        return $account;
    }

    private function generate(string $template = self::TEMPLATE): GenerateAddress
    {
        $container = self::getContainer();

        return new GenerateAddress(
            $container->get(FirmBankAccounts::class),
            $this->feeds(),
            new InboundAddresses($template),
            $container->get(MailSecrets::class),
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

    private function directory(): BankFeedDirectory
    {
        // Nothing in this task asks for it yet, so the container may have dropped the service.
        return new DoctrineBankFeedDirectory($this->connection(), self::getContainer()->get(TenantFilterBypass::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
