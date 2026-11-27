<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Application;

use Foresi\Attachment\Application\AttachmentPermissions;
use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Domain\LegalEntity;
use Foresi\ReceivedDocuments\Application\PurchasingNotAllowed;
use Foresi\ReceivedDocuments\Application\Reading\ReadReceivedDocument;
use Foresi\ReceivedDocuments\Application\ReceivedDocumentWarning;
use Foresi\ReceivedDocuments\Application\TrashReceivedDocument;
use Foresi\ReceivedDocuments\Domain\DocumentReading;
use Foresi\ReceivedDocuments\Domain\DocumentSource;
use Foresi\ReceivedDocuments\Domain\ReadingState;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentState;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class UploadReceivedDocumentsTest extends PurchasingTestCase
{
    public function testEachFileBecomesADraftWithItsOriginal(): void
    {
        $uploaded = $this->uploads()->upload([$this->file('%PDF-1.4 first', 'first.pdf'), $this->file('%PDF-1.4 second', 'second.pdf')]);

        self::assertCount(2, $uploaded);
        self::assertSame(['first.pdf', 'second.pdf'], [$uploaded[0]->fileName, $uploaded[1]->fileName]);
        foreach ($uploaded as $file) {
            self::assertTrue($file->isDrafted());
            self::assertTrue($file->warnings->isEmpty());
            $document = $this->document((string) $file->documentId);
            self::assertSame(ReceivedDocumentState::Draft, $document->state());
            self::assertNull($document->evidenceNumber());
            self::assertSame('2026-11-10', $document->receivedDate()?->format('Y-m-d'), 'Received today in the zone of the organisation.');
            self::assertSame($this->owner->user()->id(), $document->createdBy());
            self::assertSame(DocumentSource::Upload, $document->source());
            $original = $this->em->find(Attachment::class, (string) $document->originalAttachmentId());
            self::assertInstanceOf(Attachment::class, $original);
            self::assertTrue($original->belongsTo(ReceivedDocument::ATTACHMENT_KIND, $document->id()));
        }
    }

    public function testTheSameFileAgainWarnsOfTheDocumentThatHasIt(): void
    {
        $first = $this->draft('%PDF-1.4 the same bytes');

        $again = $this->uploads()->upload([$this->file('%PDF-1.4 the same bytes', 'copy.pdf')])[0];

        self::assertTrue($again->isDrafted(), 'A duplicate only warns.');
        self::assertSame([ReceivedDocumentWarning::DUPLICATE_FILE], $again->warnings->codes());
        self::assertSame([$first], $again->warnings->get(ReceivedDocumentWarning::DUPLICATE_FILE)?->documentIds);
    }

    public function testTheSameFileInTheTrashDoesNotWarn(): void
    {
        $trashed = $this->draft('%PDF-1.4 the same bytes');
        self::getContainer()->get(TrashReceivedDocument::class)->trash($trashed, 'Uploaded twice');

        $again = $this->uploads()->upload([$this->file('%PDF-1.4 the same bytes')])[0];

        self::assertTrue($again->warnings->isEmpty());
    }

    public function testAFileTheStoreRefusesLeavesNoDraftAndTheOthersGoOn(): void
    {
        $uploaded = $this->uploads()->upload([$this->file('', 'empty.pdf'), $this->file('%PDF-1.4 fine', 'fine.pdf')]);

        self::assertFalse($uploaded[0]->isDrafted());
        self::assertSame(AttachmentRefused::EMPTY, $uploaded[0]->refusal);
        self::assertTrue($uploaded[1]->isDrafted());
        self::assertCount(1, $this->em->getRepository(ReceivedDocument::class)->findAll());
        self::assertSame([$uploaded[1]->documentId], $this->queuedReadings(), 'Only the stored draft is read.');
        $readings = $this->em->getRepository(DocumentReading::class)->findAll();
        self::assertCount(1, $readings);
        self::assertSame(ReadingState::Reading, $readings[0]->state());
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        $this->uploads()->upload([$this->file()]);
    }

    public function testAMemberUploads(): void
    {
        $this->signIn($this->member);

        self::assertTrue($this->uploads()->upload([$this->file()])[0]->isDrafted());
    }

    public function testBeforeTheFirstRunSetupThereIsNoFirmToTakeTheFiles(): void
    {
        $this->em->createQuery('DELETE FROM '.LegalEntity::class)->execute();

        $this->expectException(NoFirmYet::class);
        $this->uploads()->upload([$this->file()]);
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

    /**
     * The documents whose reading the uploads queued, in their order.
     *
     * @return list<string>
     */
    private function queuedReadings(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $ids = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ReadReceivedDocument) {
                $ids[] = $message->documentId;
            }
        }

        return $ids;
    }
}
