<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application;

use App\Attachment\Application\AttachmentPermissions;
use App\Attachment\Application\AttachmentRefused;
use App\Attachment\Domain\Attachment;
use App\Party\Application\UnknownLegalEntity;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\ReceivedDocumentWarning;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\ReceivedDocumentState;
use App\Shared\Domain\Id;

final class UploadReceivedDocumentsTest extends PurchasingTestCase
{
    public function testEachFileBecomesADraftWithItsOriginal(): void
    {
        $uploaded = $this->uploads()->upload($this->firm->id(), [$this->file('%PDF-1.4 first', 'first.pdf'), $this->file('%PDF-1.4 second', 'second.pdf')]);

        self::assertCount(2, $uploaded);
        self::assertSame(['first.pdf', 'second.pdf'], [$uploaded[0]->fileName, $uploaded[1]->fileName]);
        foreach ($uploaded as $file) {
            self::assertTrue($file->isDrafted());
            self::assertTrue($file->warnings->isEmpty());
            $document = $this->document((string) $file->documentId);
            self::assertSame(ReceivedDocumentState::Draft, $document->state());
            self::assertNull($document->evidenceNumber());
            self::assertSame($this->firm->id(), $document->firm()->id());
            self::assertSame('2026-11-10', $document->receivedDate()?->format('Y-m-d'), 'Received today in the zone of the organisation.');
            self::assertSame($this->owner->user()->id(), $document->createdBy());
            $original = $this->em->find(Attachment::class, (string) $document->originalAttachmentId());
            self::assertInstanceOf(Attachment::class, $original);
            self::assertTrue($original->belongsTo(ReceivedDocument::ATTACHMENT_KIND, $document->id()));
        }
    }

    public function testTheSameFileAgainWarnsOfTheDocumentThatHasIt(): void
    {
        $first = $this->draft('%PDF-1.4 the same bytes');

        $again = $this->uploads()->upload($this->firm->id(), [$this->file('%PDF-1.4 the same bytes', 'copy.pdf')])[0];

        self::assertTrue($again->isDrafted(), 'A duplicate only warns.');
        self::assertSame([ReceivedDocumentWarning::DUPLICATE_FILE], $again->warnings->codes());
        self::assertSame([$first], $again->warnings->get(ReceivedDocumentWarning::DUPLICATE_FILE)?->documentIds);
    }

    public function testTheSameFileOfAnotherFirmOrInTheTrashDoesNotWarn(): void
    {
        $other = $this->firm('Other Firm s.r.o.', '25596641');
        $this->uploads()->upload($other->id(), [$this->file('%PDF-1.4 the same bytes')]);
        $trashed = $this->draft('%PDF-1.4 the same bytes');
        self::getContainer()->get(TrashReceivedDocument::class)->trash($trashed, 'Uploaded twice');

        $again = $this->uploads()->upload($this->firm->id(), [$this->file('%PDF-1.4 the same bytes')])[0];

        self::assertTrue($again->warnings->isEmpty());
    }

    public function testAFileTheStoreRefusesLeavesNoDraftAndTheOthersGoOn(): void
    {
        $uploaded = $this->uploads()->upload($this->firm->id(), [$this->file('', 'empty.pdf'), $this->file('%PDF-1.4 fine', 'fine.pdf')]);

        self::assertFalse($uploaded[0]->isDrafted());
        self::assertSame(AttachmentRefused::EMPTY, $uploaded[0]->refusal);
        self::assertTrue($uploaded[1]->isDrafted());
        self::assertCount(1, $this->em->getRepository(ReceivedDocument::class)->findAll());
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        $this->uploads()->upload($this->firm->id(), [$this->file()]);
    }

    public function testAMemberUploads(): void
    {
        $this->signIn($this->member);

        self::assertTrue($this->uploads()->upload($this->firm->id(), [$this->file()])[0]->isDrafted());
    }

    public function testAnUnknownFirmIsNotFound(): void
    {
        $this->expectException(UnknownLegalEntity::class);
        $this->uploads()->upload(Id::generate(), [$this->file()]);
    }

    public function testAnArchivedFirmTakesNoDocuments(): void
    {
        $this->firm->archive(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        $this->assertRefused(ReceivedDocumentRefused::FIRM_ARCHIVED, fn (): array => $this->uploads()->upload($this->firm->id(), [$this->file()]));
    }

    public function testOnlyTheStaffSeeTheOriginalAndNobodyAttachesInTheTrash(): void
    {
        $id = $this->draft();
        $permissions = self::getContainer()->get(AttachmentPermissions::class);
        $original = $this->em->find(Attachment::class, (string) $this->document($id)->originalAttachmentId());
        self::assertInstanceOf(Attachment::class, $original);

        self::assertTrue($permissions->canSee(ReceivedDocument::ATTACHMENT_KIND, $id));
        self::assertTrue($permissions->canAttach(ReceivedDocument::ATTACHMENT_KIND, $id), 'A draft may take another file.');
        self::assertFalse($permissions->canDelete($original));
        $this->signIn($this->member);
        self::assertTrue($permissions->canSee(ReceivedDocument::ATTACHMENT_KIND, $id));
        $this->signIn($this->guest);
        self::assertFalse($permissions->canSee(ReceivedDocument::ATTACHMENT_KIND, $id));
        self::assertFalse($permissions->canAttach(ReceivedDocument::ATTACHMENT_KIND, $id));

        $this->signIn($this->owner);
        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Not ours');
        self::assertTrue($permissions->canSee(ReceivedDocument::ATTACHMENT_KIND, $id), 'The trash shows the file of its document.');
        self::assertFalse($permissions->canAttach(ReceivedDocument::ATTACHMENT_KIND, $id));
    }
}
