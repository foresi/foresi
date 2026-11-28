<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Integration\ReceivedDocuments;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\ReceivedDocuments\Application\Query\ReceivedDocumentQueries;
use Foresi\ReceivedDocuments\Application\Query\ReceivedDocumentSliceFilter;
use Foresi\ReceivedDocuments\Application\ReceivedDocumentInput;
use Foresi\ReceivedDocuments\Application\ReceivedDocuments;
use Foresi\ReceivedDocuments\Application\RecordReceivedDocument;
use Foresi\ReceivedDocuments\Application\TrashReceivedDocument;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentContent;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\VatRate;
use Foresi\ReceivedDocuments\Domain\VatRecapLine;
use Foresi\ReceivedDocuments\Integration\CashFlow\PairOnDocumentEvents;
use Foresi\ReceivedDocuments\Integration\CashFlow\ReceivedDocumentPairables;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What Cash flow sees of a received document: its direction, amount and day, and which documents it sees at all.
 * The instance counts its days in Prague.
 */
final class ReceivedDocumentPairablesTest extends CashFlowTestCase
{
    private Party $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = new Party(PartyKind::Company, 'Cloud Host s.r.o.', new RegistrationNumber('CZ', '11223344'), null, '');
        $this->em->persist($this->supplier);
        $this->em->flush();
    }

    public function testATaxInvoiceIsAnOutgoingPaymentOfItsTotalOnItsTaxPoint(): void
    {
        $id = $this->record('2026-11-02', '2026-11-05', '1210.00');

        $document = $this->one($id);

        self::assertSame(FlowDirection::Out, $document->direction);
        self::assertSame(121_000, $document->amount->minor());
        self::assertSame(Currency::of('CZK'), $document->amount->currency());
        self::assertSame('2026-11-05', $document->on->format('Y-m-d'));
        self::assertSame($this->supplier->id(), $document->counterpartyId);
        self::assertSame('received_document', $document->subject->type);
        self::assertSame($id, $document->subject->id);
    }

    public function testACreditNoteIsAnIncomingPayment(): void
    {
        $original = $this->record('2026-11-02', '2026-11-02', '1210.00');
        $id = $this->record('2026-11-10', '2026-11-10', '242.00', ReceivedDocumentKind::CreditNote, $original);

        self::assertSame(FlowDirection::In, $this->one($id)->direction);
    }

    public function testWithoutATaxPointTheIssueDateIsTheDay(): void
    {
        $id = $this->record('2026-11-02', null, '1210.00');

        self::assertSame('2026-11-02', $this->one($id)->on->format('Y-m-d'));
    }

    public function testANegativeTotalIsAPositiveAmount(): void
    {
        $original = $this->record('2026-11-02', '2026-11-02', '1210.00');
        $id = $this->record('2026-11-10', '2026-11-10', '-242.00', ReceivedDocumentKind::CreditNote, $original);

        $document = $this->one($id);

        self::assertSame(FlowDirection::In, $document->direction);
        self::assertSame(24_200, $document->amount->minor());
    }

    public function testTheDayOfTheLastDayOfAMonthStaysThatDayInTheInstanceZone(): void
    {
        // 31 October is 00:00 in Prague at UTC+1 only after the clocks went back; 30 June is in summer time, UTC+2.
        // Either way the day must stay the date of the document, not move to the day before in UTC.
        foreach (['2026-10-31', '2026-06-30', '2026-11-30', '2026-12-31'] as $day) {
            $id = $this->record($day, $day, '1210.00', number: 'N-'.$day);

            $on = $this->one($id)->on;

            self::assertSame($day, $on->format('Y-m-d'), $day);
            self::assertSame('Europe/Prague', $on->getTimezone()->getName(), $day);
            self::assertSame('00:00:00', $on->format('H:i:s'), $day);
        }
    }

    public function testADraftAndATrashedDocumentAreNotSeen(): void
    {
        $draft = new ReceivedDocument(ReceivedDocumentContent::blank(), null, new \DateTimeImmutable('2026-11-17'));
        $this->em->persist($draft);
        $this->em->flush();
        $trashed = $this->record('2026-11-02', '2026-11-02', '1210.00');
        self::getContainer()->get(TrashReceivedDocument::class)->trash($trashed, 'Entered twice');
        $this->em->flush();

        self::assertNull($this->source()->one('received_document', $draft->id()));
        self::assertNull($this->source()->one('received_document', $trashed));
        self::assertNull($this->source()->one('another_type', $trashed));
        self::assertNull($this->source()->one('received_document', '0199fd8e-0000-7000-8000-000000000000'));
    }

    public function testManyAnswersTheRecordedDocumentsOfTheIdsOnly(): void
    {
        $first = $this->record('2026-11-02', '2026-11-02', '1210.00', number: 'A');
        $second = $this->record('2026-11-03', '2026-11-03', '500.00', number: 'B');
        $trashed = $this->record('2026-11-04', '2026-11-04', '300.00', number: 'C');
        self::getContainer()->get(TrashReceivedDocument::class)->trash($trashed, 'Entered twice');
        $this->record('2026-11-05', '2026-11-05', '100.00', number: 'D');
        $this->em->flush();
        $this->em->clear();

        $found = iterator_to_array($this->source()->many('received_document', [$first, $second, $trashed, '0199fd8e-0000-7000-8000-000000000000']), false);

        $ids = array_map(static fn (PairableDocument $document): string => $document->subject->id, $found);
        sort($ids);
        $expected = [$first, $second];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame([], iterator_to_array($this->source()->many('another_type', [$first]), false));
    }

    public function testOfAndSinceKeepToTheDays(): void
    {
        $this->record('2026-09-30', '2026-09-30', '100.00', number: 'A');
        $this->record('2026-10-01', '2026-10-01', '200.00', number: 'B');
        $this->record('2026-10-31', '2026-10-31', '300.00', number: 'C');
        $this->record('2026-11-01', '2026-11-01', '400.00', number: 'D');
        $this->record('2026-10-15', null, '500.00', number: 'E');
        $this->em->clear();
        $day = static fn (string $on): \DateTimeImmutable => new \DateTimeImmutable($on, new \DateTimeZone('Europe/Prague'));

        $october = $this->days($this->source()->of($this->supplier->id(), $day('2026-10-01'), $day('2026-10-31')));
        $since = $this->days($this->source()->since($day('2026-10-31')));
        $other = $this->days($this->source()->of('0199fd8e-0000-7000-8000-000000000000', $day('2026-09-01'), $day('2026-12-31')));

        sort($october);
        sort($since);
        self::assertSame(['2026-10-01', '2026-10-15', '2026-10-31'], $october);
        self::assertSame(['2026-10-31', '2026-11-01'], $since);
        self::assertSame([], $other);
    }

    public function testReconsideringATrashedDocumentForgetsItsPairing(): void
    {
        $this->writes()->create(new CreatePlannedItemInput('out', 'Cloud Host', 'software', '1210.00', '2026-05-20', 'CZK', 'month', 1, false, null, null, $this->supplier->id(), true));
        $id = $this->record('2026-11-18', '2026-11-18', '1210.00');
        $this->em->clear();
        self::assertInstanceOf(DocumentPairing::class, $this->pairing($id));
        // Trashed without the event, as the domain alone does: the listener is not the way it got there.
        $document = $this->em->find(ReceivedDocument::class, $id) ?? self::fail('No document.');
        $document->trash(new \DateTimeImmutable('2026-11-17'), null, 'Entered twice');
        $this->em->flush();

        self::getContainer()->get(PairOnDocumentEvents::class)->reconsider($id);
        $this->em->flush();

        self::assertNull($this->pairing($id));
    }

    /**
     * @param iterable<PairableDocument> $documents
     *
     * @return list<string>
     */
    private function days(iterable $documents): array
    {
        return array_values(array_unique(array_map(static fn (PairableDocument $document): string => $document->on->format('Y-m-d'), iterator_to_array($documents, false))));
    }

    private function pairing(string $id): ?DocumentPairing
    {
        $this->em->clear();

        return self::getContainer()->get(CashFlowStore::class)->pairing('received_document', $id);
    }

    public function testTheDayIsTheIssueDateBeforeTheDayItWasReceived(): void
    {
        $id = $this->record('2026-11-02', null, '1210.00', received: '2026-11-09');

        self::assertSame('2026-11-02', $this->one($id)->on->format('Y-m-d'));
    }

    public function testTheLabelIsTheSupplierNumberAndTheSupplier(): void
    {
        $id = $this->record('2026-11-02', '2026-11-02', '1210.00', number: 'INV-77');
        $blank = $this->record('2026-11-03', '2026-11-03', '1210.00', number: '');

        self::assertSame('INV-77 Cloud Host s.r.o.', $this->one($id)->subject->label);
        $evidence = $this->em->find(ReceivedDocument::class, $blank)?->evidenceNumber();
        self::assertSame(trim(($evidence ?? '').' Cloud Host s.r.o.'), $this->one($blank)->subject->label);
    }

    public function testMoreDocumentsThanOneChunkAreAllRead(): void
    {
        $first = $this->record('2026-11-02', '2026-11-02', '1210.00', number: 'A');
        $second = $this->record('2026-11-03', '2026-11-03', '500.00', number: 'B');
        $documents = [$this->em->find(ReceivedDocument::class, $first) ?? self::fail(), $this->em->find(ReceivedDocument::class, $second) ?? self::fail()];
        $calls = [];
        $queries = self::createStub(ReceivedDocumentQueries::class);
        $queries->method('slice')->willReturnCallback(static function (ReceivedDocumentSliceFilter $filter, ?string $after) use (&$calls, $documents): array {
            $calls[] = $after;

            return match ($after) {
                null => [[$documents[0]], true],
                $documents[0]->id() => [[$documents[1]], false],
                default => [[], false],
            };
        });
        $c = self::getContainer();
        $source = new ReceivedDocumentPairables($c->get(ReceivedDocuments::class), $queries, $c->get(InstanceClock::class), $c->get(UrlGeneratorInterface::class));

        $found = array_map(static fn (PairableDocument $document): string => $document->subject->id, iterator_to_array($source->since(new \DateTimeImmutable('2026-01-01')), false));

        self::assertSame([$first, $second], $found);
        self::assertSame([null, $first], $calls, 'The next chunk starts after the last document of the one before, and the last one ends the read.');
    }

    private function source(): ReceivedDocumentPairables
    {
        return self::getContainer()->get(ReceivedDocumentPairables::class);
    }

    private function one(string $id): PairableDocument
    {
        return $this->source()->one('received_document', $id) ?? self::fail('The document is not pairable.');
    }

    private function record(string $issue, ?string $taxPoint, string $total, ReceivedDocumentKind $kind = ReceivedDocumentKind::TaxInvoice, ?string $original = null, string $number = 'INV-1', ?string $received = null): string
    {
        $minor = Money::of($total, Currency::of('CZK'))->minor();
        $base = intdiv($minor * 100, 121);
        $saved = self::getContainer()->get(RecordReceivedDocument::class)->create(new ReceivedDocumentInput(
            $kind,
            PurchaseTaxTreatment::Domestic,
            $this->supplier->id(),
            $number,
            '2026123',
            new \DateTimeImmutable($issue),
            null === $taxPoint ? null : new \DateTimeImmutable($taxPoint),
            null,
            new \DateTimeImmutable($received ?? $issue),
            Currency::of('CZK'),
            Money::ofMinor($minor, Currency::of('CZK')),
            null,
            null,
            [new VatRecapLine(VatRate::of('21'), Money::ofMinor($base, Currency::of('CZK')), Money::ofMinor($minor - $base, Currency::of('CZK')))],
            $original,
        ));
        $this->em->flush();

        return $saved->document->id();
    }
}
