<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application\Query;

use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\Query\ReceivedDocumentDetails;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Application\UnknownReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Tests\Purchasing\Application\PurchasingTestCase;

final class ReceivedDocumentDetailsTest extends PurchasingTestCase
{
    public function testACreditNoteShowsTheDocumentItCorrectsAmongTheOthersOffered(): void
    {
        $other = $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-1'))->document->id();
        $original = $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-2'))->document->id();
        $note = $this->recording()->record($this->draft(), $this->input(kind: ReceivedDocumentKind::CreditNote, supplierNumber: 'D-1', base: -10_000, originalDocumentId: $original))->document->id();

        $detail = $this->details()->of($note);

        self::assertSame($note, $detail->document->id());
        self::assertSame($original, $detail->corrected?->id);
        self::assertSame('FV-2', $detail->corrected->supplierNumber);
        self::assertSame('Supplier Example s.r.o.', $detail->corrected->supplierName);
        self::assertEqualsCanonicalizing([$other, $original], array_map(static fn (\App\Purchasing\Application\Query\ReceivedDocumentRow $row): string => $row->id, $detail->correctable));
        self::assertNotEmpty($detail->history);
        self::assertNotNull($detail->document->originalAttachmentId());
        self::assertSame($detail->document->originalAttachmentId(), $detail->original?->id());
    }

    public function testADocumentThatCorrectsNothingShowsNoOriginal(): void
    {
        $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-1'));
        $id = $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-2'))->document->id();

        $detail = $this->details()->of($id);

        self::assertNull($detail->corrected);
        self::assertCount(1, $detail->correctable, 'The document itself is not offered.');
    }

    public function testACreditNoteStillShowsAnOriginalThatWentToTheTrash(): void
    {
        $original = $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-1'))->document->id();
        $note = $this->recording()->record($this->draft(), $this->input(kind: ReceivedDocumentKind::CreditNote, supplierNumber: 'D-1', base: -10_000, originalDocumentId: $original))->document->id();
        self::getContainer()->get(TrashReceivedDocument::class)->trash($original, 'Not ours');

        $detail = $this->details()->of($note);

        self::assertSame($original, $detail->corrected?->id);
        self::assertTrue($detail->corrected->isTrashed());
        self::assertSame([], $detail->correctable);
    }

    public function testADocumentOfAnotherTenantIsUnknown(): void
    {
        $this->expectException(UnknownReceivedDocument::class);

        $this->details()->of('0190b4a1-0000-7000-8000-000000000000');
    }

    public function testAGuestReadsNoDocument(): void
    {
        $id = $this->draft();
        $this->signIn($this->guest);
        $this->expectException(PurchasingNotAllowed::class);

        $this->details()->of($id);
    }

    public function testAGuestReadsNoCorrectableDocuments(): void
    {
        $this->signIn($this->guest);
        $this->expectException(PurchasingNotAllowed::class);

        $this->details()->correctable($this->firm->id());
    }

    public function testTheCorrectableDocumentsAreTheRecordedOnesOfTheFirm(): void
    {
        $first = $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-1', received: '2026-11-01'))->document->id();
        $second = $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-2', received: '2026-11-03'))->document->id();
        $this->draft('%PDF-1.4 draft');
        $this->recording()->create($this->firm('Other Firm s.r.o.', '25596641')->id(), $this->input(supplierNumber: 'FV-3'));

        $rows = $this->details()->correctable($this->firm->id());

        self::assertSame([$second, $first], array_map(static fn (\App\Purchasing\Application\Query\ReceivedDocumentRow $row): string => $row->id, $rows));
    }

    private function details(): ReceivedDocumentDetails
    {
        return self::getContainer()->get(ReceivedDocumentDetails::class);
    }
}
