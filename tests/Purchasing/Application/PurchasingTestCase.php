<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Purchasing\Application\EditReceivedDocument;
use Foresi\Purchasing\Application\ReceivedDocumentInput;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Foresi\Purchasing\Application\UploadReceivedDocuments;
use Foresi\Purchasing\Domain\ExchangeRate;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * An organisation in Prague with one firm, a supplier with its billing address,
 * an owner signed in, a member and a guest, on a fresh schema and a fixed
 * clock. The exchange rates come from a source that published on 28 October
 * and on 6 and 10 November 2026; they quote EUR but not USD.
 */
abstract class PurchasingTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected FakeRateSource $rates;
    protected LegalEntity $firm;
    protected Party $supplier;
    protected Membership $owner;
    protected Membership $member;
    protected Membership $guest;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        // The stored rates as they run, from a source that publishes what the test says.
        $this->rates = new FakeRateSource();
        $this->rates->publish('2026-10-28', '24.310', '15.120');
        $container->set(RateSource::class, $this->rates);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-10 09:00:00', 'UTC');
        Clock::set($this->clock);

        // A Czech firm gives the instance the zone Europe/Prague.
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, '');
        $this->em->persist($this->firm);
        $this->supplier = new Party(PartyKind::Company, 'Supplier Example s.r.o.', new RegistrationNumber('CZ', '87654321'), VatId::fromString('CZ87654321'), '');
        $this->em->persist($this->supplier);
        $this->em->persist(new PartyAddress($this->supplier, AddressType::Billing, new Address('Supplier Street 2', 'Example City', '110 00', 'CZ')));
        $client = new Party(PartyKind::Person, 'Example Client', null, null, '');
        $this->em->persist($client);
        $this->owner = $this->person('owner@example.com', 'Olga Owner', Role::Owner);
        $this->member = $this->person('member@example.com', 'Marek Member', Role::Member);
        $this->guest = $this->person('guest@example.com', 'Greta Guest', Role::Guest, $client);
        $this->em->flush();
        $this->signIn($this->owner);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function signIn(Membership $membership): void
    {
        $user = $membership->user();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    protected function uploads(): UploadReceivedDocuments
    {
        return self::getContainer()->get(UploadReceivedDocuments::class);
    }

    protected function recording(): RecordReceivedDocument
    {
        return self::getContainer()->get(RecordReceivedDocument::class);
    }

    protected function editing(): EditReceivedDocument
    {
        return self::getContainer()->get(EditReceivedDocument::class);
    }

    /**
     * A file on disk with these bytes, removed after the test.
     */
    protected function file(string $content = "%PDF-1.4\nAn example invoice\n%%EOF", string $name = 'invoice.pdf'): AttachmentUpload
    {
        $path = tempnam(sys_get_temp_dir(), 'received');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return new AttachmentUpload($path, $name, \strlen($content));
    }

    /**
     * A draft uploaded for the firm.
     */
    protected function draft(string $content = "%PDF-1.4\nAn example invoice\n%%EOF"): string
    {
        $uploaded = $this->uploads()->upload([$this->file($content)])[0];

        return $uploaded->documentId ?? throw new \LogicException('The file was refused: '.$uploaded->refusal);
    }

    /**
     * A domestic tax invoice of the supplier for 1,000 CZK and 21 % VAT.
     */
    protected function input(
        ReceivedDocumentKind $kind = ReceivedDocumentKind::TaxInvoice,
        PurchaseTaxTreatment $treatment = PurchaseTaxTreatment::Domestic,
        ?string $supplierId = 'supplier',
        string $supplierNumber = 'FV-2026-123',
        string $issue = '2026-10-28',
        ?string $taxPoint = '2026-10-28',
        ?string $received = '2026-11-02',
        Currency $currency = Currency::CZK,
        int $base = 100_000,
        ?int $vat = null,
        ?ExchangeRate $rate = null,
        ?string $originalDocumentId = null,
        ?string $supplyCode = null,
    ): ReceivedDocumentInput {
        $vat ??= $treatment->isSelfAssessed() || PurchaseTaxTreatment::NotVatDocument === $treatment ? 0 : intdiv($base * 21, 100);
        $recap = $kind->needsVatRecap() ? [new VatRecapLine(VatRate::of(PurchaseTaxTreatment::NotVatDocument === $treatment ? '0' : '21'), Money::ofMinor($base, $currency), Money::ofMinor($vat, $currency))] : [];

        return new ReceivedDocumentInput(
            $kind,
            $treatment,
            'supplier' === $supplierId ? $this->supplier->id() : $supplierId,
            $supplierNumber,
            '2026123',
            new \DateTimeImmutable($issue),
            null === $taxPoint ? null : new \DateTimeImmutable($taxPoint),
            new \DateTimeImmutable('2026-11-11'),
            null === $received ? null : new \DateTimeImmutable($received),
            $currency,
            Money::ofMinor($base + $vat, $currency),
            $rate,
            null,
            $recap,
            $originalDocumentId,
            $supplyCode,
        );
    }

    protected function document(string $id): ReceivedDocument
    {
        $this->em->clear();

        return $this->em->find(ReceivedDocument::class, $id) ?? throw new \LogicException('No such document.');
    }

    protected function assertRefused(string $reason, \Closure $change): void
    {
        try {
            $change();
            self::fail('The change was taken.');
        } catch (ReceivedDocumentRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function person(string $email, string $name, Role $role, ?Party $party = null): Membership
    {
        $user = new User($email, 'hash', $name);
        $membership = new Membership($user, $role, $party);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }
}
