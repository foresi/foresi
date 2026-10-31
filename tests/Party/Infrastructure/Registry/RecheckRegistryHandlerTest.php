<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Registry;

use App\Audit\Domain\AuditEntry;
use App\Party\Application\CreateParty;
use App\Party\Application\PartyQuery;
use App\Party\Application\RecheckRegistry;
use App\Party\Domain\Address;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\VatId;
use App\Party\Infrastructure\Registry\RecheckRegistryHandler;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Support\Registry\RegistryCalls;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class RecheckRegistryHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantId $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->tenant = TenantId::generate();
        self::getContainer()->get(TenantContext::class)->switchTo($this->tenant);
        RegistryCalls::reset();
    }

    public function testTheRecheckIsRoutedToTheRegistryTransportWithTheTenant(): void
    {
        $this->bus()->dispatch(new RecheckRegistry(1));

        $sent = $this->registry()->getSent();
        self::assertCount(1, $sent);
        self::assertSame($this->tenant->toString(), $sent[0]->last(TenantStamp::class)?->tenantId);
    }

    public function testTheChecksAreStoredUnderTheCorrelationOfTheImport(): void
    {
        $party = $this->party('CZ12345687');

        $this->handle(new RecheckRegistry((int) $party->id(), 'import:7'));

        $party = $this->find((int) $party->id());
        self::assertSame(RegistryResult::Valid, $party->viesCheck()?->result);
        self::assertSame(RegistryResult::Unreliable, $party->unreliablePayerCheck()?->result);
        self::assertNotSame([], $party->publishedBankAccounts());
        self::assertSame(['import:7'], $this->em->createQuery('SELECT DISTINCT e.correlationId FROM '.AuditEntry::class." e WHERE e.action = 'updated'")->getSingleColumnResult());
    }

    public function testWithoutACorrelationTheChecksAreStoredAsTheirOwnChange(): void
    {
        $party = $this->party('CZ12345687');

        $this->handle(new RecheckRegistry((int) $party->id()));

        self::assertSame(RegistryResult::Valid, $this->find((int) $party->id())->viesCheck()?->result);
        self::assertSame([null], $this->em->createQuery('SELECT DISTINCT e.correlationId FROM '.AuditEntry::class." e WHERE e.action = 'updated'")->getSingleColumnResult());
    }

    public function testAPartyWithoutAVatIdOrAGonePartyAsksNoRegister(): void
    {
        $party = $this->party(null);

        $this->handle(new RecheckRegistry((int) $party->id()));
        $this->handle(new RecheckRegistry(999999));

        self::assertSame(0, RegistryCalls::$count);
        self::assertCount(0, $this->registry()->getSent());
    }

    public function testWhenTheRegistersAreBusyTheRecheckWaitsAMinute(): void
    {
        $party = $this->party('CZ12345687');
        $limiter = $this->limiter();
        // Leave exactly the headroom: people typing in the contact form keep it.
        $limiter->create('adis')->consume(300 - RecheckRegistryHandler::HEADROOM);

        $message = new RecheckRegistry((int) $party->id(), 'import:7');
        $this->handle($message);

        self::assertSame(0, RegistryCalls::$count);
        self::assertNull($this->find((int) $party->id())->viesCheck());
        $sent = $this->registry()->getSent();
        self::assertCount(1, $sent);
        self::assertEquals($message, $sent[0]->getMessage());
        self::assertSame(RecheckRegistryHandler::RETRY_DELAY_MS, $sent[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testAForeignVatIdNeedsOnlyTheBudgetOfVies(): void
    {
        $party = $this->party('DE123456789');
        // The Czech register is busy, but a German VAT id is checked in VIES only.
        $this->limiter()->create('adis')->consume(300);

        $this->handle(new RecheckRegistry((int) $party->id()));

        self::assertNotNull($this->find((int) $party->id())->viesCheck());
        self::assertCount(0, $this->registry()->getSent());
    }

    public function testOneCallMoreThanTheHeadroomIsEnough(): void
    {
        $party = $this->party('CZ12345687');
        $limiter = $this->limiter();
        $limiter->create('vies')->consume(300 - RecheckRegistryHandler::HEADROOM - 1);

        $this->handle(new RecheckRegistry((int) $party->id()));

        self::assertSame(RegistryResult::Valid, $this->find((int) $party->id())->viesCheck()?->result);
        self::assertCount(0, $this->registry()->getSent());
    }

    private function limiter(): RateLimiterFactory
    {
        return self::getContainer()->get('limiter.registry_outgoing');
    }

    private function handle(RecheckRegistry $message): void
    {
        $this->bus()->dispatch(new Envelope($message, [new ReceivedStamp('registry'), new TenantStamp($this->tenant->toString())]));
    }

    private function party(?string $vatId): Party
    {
        return self::getContainer()->get(CreateParty::class)->create(
            null === $vatId ? PartyKind::Person : PartyKind::Company,
            'Example Company',
            null,
            null === $vatId ? null : VatId::fromString($vatId),
            '',
            '',
            [],
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
        );
    }

    private function find(int $id): Party
    {
        $this->em->clear();

        return self::getContainer()->get(PartyQuery::class)->find($id) ?? throw new \LogicException('No such party.');
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function registry(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.registry');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
