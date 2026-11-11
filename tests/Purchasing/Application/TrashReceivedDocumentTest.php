<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application;

use App\Attachment\Domain\Attachment;
use App\Purchasing\Application\DeleteReceivedDocumentDraft;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\RestoreReceivedDocument;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Application\UnknownReceivedDocument;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentRefused;

final class TrashReceivedDocumentTest extends PurchasingTestCase
{
    public function testARecordedDocumentGoesToTheTrashAndComesBackWithItsNumber(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $this->trashing()->trash($id, ' Recorded twice ');

        $trashed = $this->document($id);
        self::assertTrue($trashed->isTrashed());
        self::assertSame('Recorded twice', $trashed->deletedReason());
        self::assertSame($this->owner->user()->id(), $trashed->deletedBy());
        self::assertSame('R20260001', $trashed->evidenceNumber());

        $this->restoring()->restore($id);

        $restored = $this->document($id);
        self::assertFalse($restored->isTrashed());
        self::assertTrue($restored->isRecorded());
        self::assertSame('R20260001', $restored->evidenceNumber());
        self::assertNotNull($restored->originalAttachmentId());
        self::assertSame('R20260002', $this->recording()->record($this->draft('%PDF-1.4 next'), $this->input(supplierNumber: 'FV-2'))->document->evidenceNumber(), 'The trash gave no number back.');
    }

    public function testTheTrashNeedsAReasonAndTakesADocumentOnce(): void
    {
        $id = $this->draft();

        $this->assertRefused(ReceivedDocumentRefused::REASON_MISSING, fn (): ReceivedDocument => $this->trashing()->trash($id, ' '));
        $this->trashing()->trash($id, 'Not ours');
        $this->assertRefused(ReceivedDocumentRefused::TRASHED, fn (): ReceivedDocument => $this->trashing()->trash($id, 'Again'));
    }

    public function testRestoringADocumentOutsideTheTrashChangesNothing(): void
    {
        $id = $this->draft();
        $version = $this->document($id)->version();

        $this->restoring()->restore($id);

        self::assertSame($version, $this->document($id)->version());
    }

    public function testADraftIsDeletedForGoodWithItsFile(): void
    {
        $id = $this->draft();
        $original = $this->em->find(Attachment::class, (string) $this->document($id)->originalAttachmentId());
        self::assertInstanceOf(Attachment::class, $original);
        $file = self::getContainer()->getParameter('app.attachments_dir').'/'.$original->storageKey();
        self::assertFileExists($file);

        self::getContainer()->get(DeleteReceivedDocumentDraft::class)->delete($id);

        $this->em->clear();
        self::assertNull($this->em->find(ReceivedDocument::class, $id));
        self::assertNull($this->em->find(Attachment::class, $original->id()));
        self::assertFileDoesNotExist($file);
    }

    public function testADraftInTheTrashIsDeletedToo(): void
    {
        $id = $this->draft();
        $this->trashing()->trash($id, 'Not ours');

        self::getContainer()->get(DeleteReceivedDocumentDraft::class)->delete($id);

        $this->em->clear();
        self::assertNull($this->em->find(ReceivedDocument::class, $id));
    }

    public function testARecordedDocumentIsNeverDeleted(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $this->assertRefused(ReceivedDocumentRefused::RECORDED, fn () => self::getContainer()->get(DeleteReceivedDocumentDraft::class)->delete($id));
        self::assertTrue($this->document($id)->isRecorded());
    }

    public function testADocumentOfAnotherTenantIsNotFound(): void
    {
        $this->expectException(UnknownReceivedDocument::class);
        $this->trashing()->trash('0190b4a1-0000-7000-8000-000000000000', 'Not ours');
    }

    public function testAGuestIsRefusedEverything(): void
    {
        $id = $this->draft();
        $this->signIn($this->guest);

        foreach ([
            fn (): ReceivedDocument => $this->trashing()->trash($id, 'Not ours'),
            fn (): ReceivedDocument => $this->restoring()->restore($id),
            fn () => self::getContainer()->get(DeleteReceivedDocumentDraft::class)->delete($id),
        ] as $change) {
            try {
                $change();
                self::fail('A guest changed a document.');
            } catch (PurchasingNotAllowed) {
            }
        }
        $this->signIn($this->owner);
        self::assertFalse($this->document($id)->isTrashed());
    }

    private function trashing(): TrashReceivedDocument
    {
        return self::getContainer()->get(TrashReceivedDocument::class);
    }

    private function restoring(): RestoreReceivedDocument
    {
        return self::getContainer()->get(RestoreReceivedDocument::class);
    }
}
