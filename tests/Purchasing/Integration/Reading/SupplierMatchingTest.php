<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Integration\Reading;

use Foresi\Attachment\Application\AttachmentFiles;
use Foresi\Attachment\Application\Attachments;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Purchasing\Application\PurchasingUnit;
use Foresi\Purchasing\Application\Reading\DocumentReadings;
use Foresi\Purchasing\Application\Reading\ReadDocument;
use Foresi\Purchasing\Application\Reading\ReadingApplication;
use Foresi\Purchasing\Application\Reading\ReadingMerge;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Foresi\Purchasing\Application\Reading\SupplierMatching;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\ReceivedDocumentWarning;
use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\DocumentReader;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Contract\Reading\ReadValue;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Tests\Purchasing\Application\PurchasingTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The supplier a reading names is matched to one active party, by registration
 * number first and then by VAT id, and a draft nobody touched takes it.
 */
final class SupplierMatchingTest extends PurchasingTestCase
{
    public function testMatchesByRegistrationNumber(): void
    {
        $match = $this->matching()->match($this->named(registrationNumber: '876 54321', country: 'CZ'));

        self::assertSame($this->supplier->id(), $match->party?->id());
        self::assertNull($match->warning);
        self::assertSame('Supplier Example s.r.o.', $match->snapshot?->name);
        self::assertSame('Supplier Street 2', $match->snapshot->address->street, 'The billing address, as the form takes it for a chosen contact.');
    }

    public function testMatchesByVatIdWhenNoRegistrationNumber(): void
    {
        $match = $this->matching()->match($this->named(vatId: 'CZ 87654321'));

        self::assertSame($this->supplier->id(), $match->party?->id());
        self::assertNull($match->warning);
    }

    public function testTheRegistrationNumberDecidesBeforeTheVatId(): void
    {
        $other = $this->party('Other Example s.r.o.', '11111111', 'CZ11111111');

        // The number belongs to the other party, the VAT id to the supplier: the number wins.
        $match = $this->matching()->match($this->named(registrationNumber: '11111111', country: 'CZ', vatId: 'CZ87654321'));

        self::assertSame($other->id(), $match->party?->id());
    }

    public function testFallsBackToTheVatIdWhenTheRegistrationNumberFindsNobody(): void
    {
        $match = $this->matching()->match($this->named(registrationNumber: '99999999', country: 'CZ', vatId: 'CZ87654321'));

        self::assertSame($this->supplier->id(), $match->party?->id());
    }

    public function testIgnoresArchivedParties(): void
    {
        $this->supplier->archive(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        $match = $this->matching()->match($this->named(registrationNumber: '87654321', country: 'CZ', vatId: 'CZ87654321'));

        self::assertNull($match->party);
        self::assertSame(ReceivedDocumentWarning::SUPPLIER_NOT_FOUND, $match->warning);

        // An archived twin never makes an active party ambiguous.
        $active = $this->party('Supplier Example Again s.r.o.', '87654321', 'CZ87654321');
        $again = $this->matching()->match($this->named(registrationNumber: '87654321', country: 'CZ'));
        self::assertSame($active->id(), $again->party?->id());
    }

    public function testTwoMatchesSetNoSupplier(): void
    {
        $this->party('Supplier Twin s.r.o.', '87654321', null);

        $match = $this->matching()->match($this->named(registrationNumber: '87654321', country: 'CZ'));

        self::assertNull($match->party);
        self::assertSame(ReceivedDocumentWarning::SUPPLIER_AMBIGUOUS, $match->warning);

        $message = $this->uploaded();
        $this->readDocument($this->stub(fn (): Reading => $this->named(name: 'Supplier Example s.r.o.', registrationNumber: '87654321', country: 'CZ')))->read($message);

        $document = $this->document($message->documentId);
        self::assertNull($document->supplierParty(), 'Two matches: the draft gets no supplier.');
        self::assertSame('', $document->supplier()->name);
        $reading = $this->reading($message->documentId);
        self::assertContains(ReceivedDocumentWarning::SUPPLIER_AMBIGUOUS, $reading->warnings());
    }

    public function testAReadingThatNamesNobodyLooksForNobody(): void
    {
        $match = $this->matching()->match(new Reading([new ReadValue(ReadField::Total, 12_100, 'isdoc')]));

        self::assertNull($match->party);
        self::assertNull($match->warning);
    }

    public function testAHostileValueIsOnlyCompared(): void
    {
        $match = $this->matching()->match($this->named(registrationNumber: "87654321'; DROP TABLE party;--", country: 'CZ', vatId: 'not a vat id'));

        self::assertNull($match->party);
        self::assertSame(ReceivedDocumentWarning::SUPPLIER_NOT_FOUND, $match->warning);
    }

    public function testAnUntouchedDraftTakesTheMatchedSupplierAndItsSnapshot(): void
    {
        $message = $this->uploaded();

        $this->readDocument($this->stub(fn (): Reading => $this->named(name: 'Read Name', registrationNumber: '87654321', country: 'CZ')))->read($message);

        $document = $this->document($message->documentId);
        self::assertSame($this->supplier->id(), $document->supplierParty()?->id());
        self::assertSame('Supplier Example s.r.o.', $document->supplier()->name, 'The contact, not the read name.');
        self::assertSame('87654321', $document->supplier()->registrationNumber);
        self::assertSame('Supplier Street 2', $document->supplier()->address->street);
        $reading = $this->reading($message->documentId);
        self::assertContains('supplier_name', $reading->applied());
        self::assertSame([], $reading->warnings());
    }

    public function testNoMatchGivesTheSnapshotTheReadValues(): void
    {
        $message = $this->uploaded();

        $this->readDocument($this->stub(fn (): Reading => $this->named(name: 'Unknown Example Ltd', registrationNumber: '55555555', country: 'GB', vatId: 'GB123456789')))->read($message);

        $document = $this->document($message->documentId);
        self::assertNull($document->supplierParty());
        self::assertSame('Unknown Example Ltd', $document->supplier()->name, 'So the detail can offer to create the supplier.');
        self::assertSame('55555555', $document->supplier()->registrationNumber);
        self::assertSame('GB123456789', $document->supplier()->vatId);
        self::assertSame('GB', $document->supplier()->address->countryCode);
        self::assertContains(ReceivedDocumentWarning::SUPPLIER_NOT_FOUND, $this->reading($message->documentId)->warnings());
    }

    public function testASupplierTheDraftHasIsNeverOverwritten(): void
    {
        $message = $this->uploaded();
        $mine = $this->party('Chosen Example s.r.o.', '22222222', 'CZ22222222');
        $draft = $this->document($message->documentId);
        $this->editing()->edit($message->documentId, $this->input(supplierId: $mine->id(), supplierNumber: ''), $draft->version());
        // The person's save moved the version; the job was asked for the version after the upload.
        $this->forceBase($message, $this->document($message->documentId)->version());

        $this->readDocument($this->stub(fn (): Reading => $this->named(name: 'Supplier Example s.r.o.', registrationNumber: '87654321', country: 'CZ')))->read(new ReadReceivedDocument($message->documentId, $this->document($message->documentId)->version(), false, null));

        $document = $this->document($message->documentId);
        self::assertSame($mine->id(), $document->supplierParty()?->id());
        self::assertSame('Chosen Example s.r.o.', $document->supplier()->name);
    }

    public function testATouchedDraftOnlyGetsASuggestion(): void
    {
        $message = $this->uploaded();

        $this->readDocument($this->stub(function () use ($message): Reading {
            // A person saves the draft, without a supplier, while the file is being read.
            $this->editing()->edit($message->documentId, $this->input(supplierId: null, supplierNumber: 'TYPED-1'));

            return $this->named(name: 'Supplier Example s.r.o.', registrationNumber: '87654321', country: 'CZ');
        }))->read($message);

        $document = $this->document($message->documentId);
        self::assertNull($document->supplierParty(), 'A suggestion is not written.');
        self::assertSame('TYPED-1', $document->supplierNumber());
        $reading = $this->reading($message->documentId);
        self::assertSame([], $reading->applied());
        self::assertContains('supplier_name', $reading->suggested());
    }

    public function testMatchedSupplierBringsMarginDefaultRecipient(): void
    {
        // Named by string: Margin is optional, so nothing here may need its classes to load.
        $writesId = 'Foresi\\Margin\\Application\\AllocationWrites';
        $readsId = 'Foresi\\Margin\\Application\\AllocationReads';
        try {
            if (!class_exists($writesId)) {
                throw new ServiceNotFoundException($writesId);
            }
            $writes = self::getContainer()->get($writesId);
        } catch (ServiceNotFoundException) {
            self::markTestSkipped('The margin module is not installed, so there is no default recipient to follow.');
        }
        $clientA = $this->party('Client A s.r.o.', '33333333', null);
        $recipient = [\Foresi\Margin\Domain\Recipient::class, 'client']($clientA->id());
        $writes->setSupplierDefault($this->supplier->id(), $recipient, '2026-10');
        $message = $this->uploaded();
        $this->readDocument($this->stub(fn (): Reading => $this->named(name: 'Supplier Example s.r.o.', registrationNumber: '87654321', country: 'CZ')))->read($message);
        $draft = $this->document($message->documentId);
        self::assertSame($this->supplier->id(), $draft->supplierParty()?->id());

        // A person records the draft as it stands: the default of the supplier, in force from October, follows.
        $this->recording()->record($message->documentId, $this->input(supplierId: $this->supplier->id()), $draft->version());

        $view = self::getContainer()->get($readsId)->document($message->documentId);
        $shares = $view->lines[0]->shares;
        self::assertCount(1, $shares);
        self::assertSame($clientA->id(), $shares[0]->recipient);
        self::assertSame('supplier', $shares[0]->source);
    }

    private function forceBase(ReadReceivedDocument $message, int $version): void
    {
        $reading = $this->em->find(DocumentReading::class, $message->documentId) ?? throw new \LogicException('No reading.');
        $reading->start($version, new \DateTimeImmutable('2026-11-10 10:00:00'));
        $this->em->flush();
    }

    private function matching(): SupplierMatching
    {
        return self::getContainer()->get(SupplierMatching::class);
    }

    private function party(string $name, ?string $number, ?string $vat): Party
    {
        $party = new Party(PartyKind::Company, $name, null === $number ? null : new RegistrationNumber('CZ', $number), null === $vat ? null : VatId::fromString($vat), '');
        $this->em->persist($party);
        $this->em->persist(new PartyAddress($party, AddressType::Billing, new Address('Street 1', 'Example City', '100 00', 'CZ')));
        $this->em->flush();

        return $party;
    }

    private function named(?string $name = null, ?string $registrationNumber = null, ?string $country = null, ?string $vatId = null): Reading
    {
        $values = [];
        foreach ([[ReadField::SupplierName, $name], [ReadField::SupplierRegistrationNumber, $registrationNumber], [ReadField::SupplierCountry, $country], [ReadField::SupplierVatId, $vatId]] as [$field, $value]) {
            if (null !== $value) {
                $values[] = new ReadValue($field, $value, 'isdoc');
            }
        }

        return new Reading($values);
    }

    private function uploaded(): ReadReceivedDocument
    {
        $this->draft();
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ReadReceivedDocument) {
                return $message;
            }
        }
        throw new \LogicException('No reading was queued.');
    }

    private function reading(string $documentId): DocumentReading
    {
        $this->em->clear();

        return $this->em->find(DocumentReading::class, $documentId) ?? throw new \LogicException('No reading.');
    }

    /**
     * @param \Closure(): Reading $read
     */
    private function stub(\Closure $read): DocumentReader
    {
        return new readonly class($read) implements DocumentReader {
            /**
             * @param \Closure(): Reading $reader
             */
            public function __construct(private \Closure $reader)
            {
            }

            public function source(): string
            {
                return 'isdoc';
            }

            public function supports(DocumentFile $file): bool
            {
                return true;
            }

            public function automatic(): bool
            {
                return true;
            }

            public function read(DocumentFile $file): Reading
            {
                return ($this->reader)();
            }
        };
    }

    private function readDocument(DocumentReader $reader): ReadDocument
    {
        $container = self::getContainer();

        return new ReadDocument(
            [$reader],
            new ReadingMerge(),
            $container->get(ReceivedDocuments::class),
            $container->get(DocumentReadings::class),
            $container->get(ReadingApplication::class),
            $container->get(Attachments::class),
            $container->get(AttachmentFiles::class),
            $container->get(PurchasingUnit::class),
            $container->get(AuditCorrelation::class),
            $container->get(ClockInterface::class),
        );
    }
}
