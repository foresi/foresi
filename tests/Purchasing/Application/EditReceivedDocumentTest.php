<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application;

use App\Audit\Domain\AuditEntry;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\SavedReceivedDocument;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\ReceivedDocumentState;
use App\Shared\Domain\Currency;

final class EditReceivedDocumentTest extends PurchasingTestCase
{
    public function testADraftTakesWhatIsGivenCompleteOrNot(): void
    {
        $draft = $this->draft();

        $saved = $this->editing()->edit($draft, $this->input(supplierId: null, received: null));

        $document = $this->document($draft);
        self::assertSame(ReceivedDocumentState::Draft, $document->state());
        self::assertNull($document->evidenceNumber());
        self::assertSame('FV-2026-123', $document->supplierNumber());
        self::assertSame(121_000, $document->total()?->minor());
        self::assertSame($saved->document->version(), $document->version());
    }

    public function testADraftInAnotherCurrencyTakesTheRateWhenItIsKnown(): void
    {
        $draft = $this->draft();

        $this->editing()->edit($draft, $this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::EuService, currency: Currency::EUR, base: 10_000));
        self::assertSame('24.31', $this->document($draft)->exchangeRate()?->toString());

        $this->editing()->edit($draft, $this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::NonEuService, currency: Currency::USD, base: 10_000));
        self::assertNull($this->document($draft)->exchangeRate(), 'A draft without a published rate is kept without one.');
    }

    public function testARecordedDocumentKeepsItsNumberAndItsFrozenSupplier(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();
        $this->supplier->rename('Renamed Supplier s.r.o.');
        $this->em->flush();

        $this->editing()->edit($id, $this->input(supplierNumber: 'FV-2026-999', base: 200_000));

        $document = $this->document($id);
        self::assertTrue($document->isRecorded());
        self::assertSame('R20260001', $document->evidenceNumber());
        self::assertSame('FV-2026-999', $document->supplierNumber());
        self::assertSame(242_000, $document->total()?->minor());
        self::assertSame('Supplier Example s.r.o.', $document->supplier()->name, 'The same supplier keeps what the document froze.');
    }

    public function testARecordedDocumentThatChangesItsSupplierFreezesTheNewOne(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();
        $other = new Party(PartyKind::Person, 'Other Supplier', null, null, '');
        $this->em->persist($other);
        $this->em->flush();

        $this->editing()->edit($id, $this->input(supplierId: $other->id()));

        self::assertSame('Other Supplier', $this->document($id)->supplier()->name);
    }

    public function testARecordedDocumentStaysComplete(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $this->assertRefused(ReceivedDocumentRefused::SUPPLIER_MISSING, fn (): SavedReceivedDocument => $this->editing()->edit($id, $this->input(supplierId: null)));
    }

    public function testEveryChangeIsAudited(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $this->editing()->edit($id, $this->input(supplierNumber: 'FV-2026-999'));

        $entries = $this->em->getRepository(AuditEntry::class)->findBy(['entityClass' => ReceivedDocument::class, 'entityId' => $id]);
        self::assertGreaterThanOrEqual(3, \count($entries), 'Uploaded, recorded and edited.');
    }

    public function testADocumentInTheTrashTakesNoChange(): void
    {
        $id = $this->draft();
        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Not ours');

        $this->assertRefused(ReceivedDocumentRefused::TRASHED, fn (): SavedReceivedDocument => $this->editing()->edit($id, $this->input()));
    }

    public function testAnEditOpenedBeforeTheLastSaveIsRefused(): void
    {
        $id = $this->draft();
        $version = $this->document($id)->version();
        $this->editing()->edit($id, $this->input(), $version);

        $this->assertRefused(ReceivedDocumentRefused::STALE, fn (): SavedReceivedDocument => $this->editing()->edit($id, $this->input(), $version));
    }

    public function testAGuestIsRefused(): void
    {
        $id = $this->draft();
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        $this->editing()->edit($id, $this->input());
    }
}
