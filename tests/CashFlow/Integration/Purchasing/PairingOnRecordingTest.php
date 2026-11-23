<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Integration\Purchasing;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Purchasing\Application\ReceivedDocumentInput;
use Foresi\Purchasing\Application\EditReceivedDocument;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Foresi\Purchasing\Application\RestoreReceivedDocument;
use Foresi\Purchasing\Application\TrashReceivedDocument;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Recording, changing, trashing and restoring a received document let Cash flow pair it with a planned
 * occurrence, or forget it, in the same unit of work; the backfill command pairs documents recorded before. Today is 17 November 2026 in Prague.
 */
final class PairingOnRecordingTest extends CashFlowTestCase
{
    private ?Party $supplier = null;

    public function testRecordingADocumentPairsItWithTheOccurrenceOfItsSupplier(): void
    {
        $item = $this->monthlyFor('Cloud Host', '1210.00');

        $id = $this->record('2026-11-18', '1210.00');

        $row = $this->pairing($id) ?? self::fail('No pairing.');
        self::assertSame(PairingState::Auto, $row->state());
        self::assertSame($item->id(), $row->plannedItemId());
        self::assertSame('2026-11-20', $row->occurrenceOn()?->format('Y-m-d'));
        self::assertCount(1, $this->events('cash-flow.document_pairing.changed'));
    }

    public function testADocumentOfAnotherAmountIsProposed(): void
    {
        $this->monthlyFor('Cloud Host', '500.00');

        $id = $this->record('2026-11-18', '1210.00');

        self::assertSame(PairingState::Proposed, ($this->pairing($id) ?? self::fail('No pairing.'))->state());
    }

    public function testADocumentOfASupplierWithoutAPlannedItemHasNoPairing(): void
    {
        $id = $this->record('2026-11-18', '1210.00');

        self::assertNull($this->pairing($id));
    }

    public function testTheBackfillPairsDocumentsRecordedBeforeAndRunsTwiceWithTheSameResult(): void
    {
        $id = $this->record('2026-11-18', '1210.00');
        $this->monthlyFor('Cloud Host', '1210.00');
        $before = $this->pairing($id);

        $first = $this->backfill();
        $second = $this->backfill();

        self::assertNull($before);
        self::assertSame(PairingState::Auto, ($this->pairing($id) ?? self::fail('No pairing.'))->state());
        self::assertStringContainsString('Considered 1 documents', $first);
        self::assertStringContainsString('auto', $first);
        self::assertSame($first, $second);
        self::assertCount(1, $this->events('cash-flow.document_pairing.changed'));
    }

    public function testADocumentOlderThanThirteenMonthsIsLeftAlone(): void
    {
        $id = $this->record('2025-09-18', '1210.00');
        $this->monthlyFor('Cloud Host', '1210.00', '2025-01-20');
        $this->backfill();

        self::assertNull($this->pairing($id));
    }

    public function testChangingADocumentPairsItAgainByItsNewValues(): void
    {
        $item = $this->monthlyFor('Cloud Host', '1210.00');
        $id = $this->record('2026-11-18', '1210.00');
        $auto = $this->pairing($id) ?? self::fail('No pairing.');
        self::assertSame(PairingState::Auto, $auto->state());

        self::getContainer()->get(EditReceivedDocument::class)->edit($id, $this->input('2026-11-18', '1500.00'));
        $this->em->flush();

        $row = $this->pairing($id) ?? self::fail('No pairing.');
        self::assertSame(PairingState::Proposed, $row->state());
        self::assertSame($item->id(), $row->plannedItemId());
        self::assertCount(2, $this->events('cash-flow.document_pairing.changed'));
    }

    public function testTrashingADocumentRemovesItsPairing(): void
    {
        $this->monthlyFor('Cloud Host', '1210.00');
        $id = $this->record('2026-11-18', '1210.00');
        self::assertInstanceOf(DocumentPairing::class, $this->pairing($id));

        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Entered twice');
        $this->em->flush();

        self::assertNull($this->pairing($id));
        self::assertCount(2, $this->events('cash-flow.document_pairing.changed'));
    }

    public function testRestoringADocumentPairsItAgain(): void
    {
        $item = $this->monthlyFor('Cloud Host', '1210.00');
        $id = $this->record('2026-11-18', '1210.00');
        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Entered twice');
        $this->em->flush();
        self::assertNull($this->pairing($id));

        self::getContainer()->get(RestoreReceivedDocument::class)->restore($id);
        $this->em->flush();

        $row = $this->pairing($id) ?? self::fail('No pairing.');
        self::assertSame(PairingState::Auto, $row->state());
        self::assertSame($item->id(), $row->plannedItemId());
    }

    private function monthlyFor(string $name, string $amount, string $on = '2026-05-20'): PlannedItem
    {
        return $this->writes()->create(new CreatePlannedItemInput('out', $name, 'software', $amount, $on, 'CZK', 'month', 1, false, null, null, $this->supplierId(), true));
    }

    private function supplierId(): string
    {
        if (!$this->supplier instanceof Party) {
            $this->supplier = new Party(PartyKind::Company, 'Cloud Host s.r.o.', new RegistrationNumber('CZ', '11223344'), null, '');
            $this->em->persist($this->supplier);
            $this->em->flush();
        }

        return $this->supplier->id();
    }

    private function record(string $day, string $total): string
    {
        $saved = self::getContainer()->get(RecordReceivedDocument::class)->create($this->input($day, $total));
        $this->em->flush();

        return $saved->document->id();
    }

    private function input(string $day, string $total): ReceivedDocumentInput
    {
        $vat = Money::of($total, Currency::CZK)->minor();
        $base = intdiv($vat * 100, 121);

        return new ReceivedDocumentInput(
            ReceivedDocumentKind::TaxInvoice,
            PurchaseTaxTreatment::Domestic,
            $this->supplierId(),
            'INV-'.$day,
            '2026123',
            new \DateTimeImmutable($day),
            new \DateTimeImmutable($day),
            null,
            new \DateTimeImmutable($day),
            Currency::CZK,
            Money::ofMinor($vat, Currency::CZK),
            null,
            null,
            [new VatRecapLine(VatRate::of('21'), Money::ofMinor($base, Currency::CZK), Money::ofMinor($vat - $base, Currency::CZK))],
        );
    }

    private function pairing(string $documentId): ?DocumentPairing
    {
        $this->em->clear();

        return self::getContainer()->get(CashFlowStore::class)->pairing('received_document', $documentId);
    }

    private function backfill(): string
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('cashflow:pairing:backfill'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }
}
