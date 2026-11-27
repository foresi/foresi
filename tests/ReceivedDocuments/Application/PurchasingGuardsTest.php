<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Application;

use Foresi\Attachment\Application\UploadAttachment;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Infrastructure\Doctrine\DoctrineLegalEntities;
use Foresi\ReceivedDocuments\Application\DeleteReceivedDocumentDraft;
use Foresi\ReceivedDocuments\Application\DocumentLock;
use Foresi\ReceivedDocuments\Application\PurchasingNotAllowed;
use Foresi\ReceivedDocuments\Application\RestoreReceivedDocument;
use Foresi\ReceivedDocuments\Application\SavedReceivedDocument;
use Foresi\ReceivedDocuments\Application\TrashReceivedDocument;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;

/**
 * The guards around the use cases: the firm is locked where a document is
 * written, one correlation id groups a step, a locked document stays as it is.
 */
final class PurchasingGuardsTest extends PurchasingTestCase
{
    private WatchedLegalEntities $firms;

    public function testAnActionIsOneStepUnderOneCorrelationId(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $correlations = [];
        foreach ($this->em->getRepository(AuditEntry::class)->findBy(['entityClass' => ReceivedDocument::class, 'entityId' => $id]) as $entry) {
            $correlations[] = (string) $entry->correlationId();
        }
        self::assertNotSame([], $correlations);
        foreach ($correlations as $correlation) {
            self::assertStringStartsWith('purchasing:', $correlation);
            self::assertTrue(Id::isValid(substr($correlation, \strlen('purchasing:'))), $correlation);
        }
    }

    public function testAStepInsideAnotherKeepsTheCorrelationOfTheOuterOne(): void
    {
        $draft = $this->draft();

        $id = self::getContainer()->get(AuditCorrelation::class)->within('import:example', fn (): string => $this->recording()->record($draft, $this->input())->document->id());

        $entries = $this->em->getRepository(AuditEntry::class)->findBy(['entityClass' => ReceivedDocument::class, 'entityId' => $id]);
        $recorded = array_values(array_filter($entries, static fn (AuditEntry $entry): bool => 'import:example' === $entry->correlationId()));
        self::assertNotSame([], $recorded);
    }

    public function testWhoIsRefusedIsToldWhy(): void
    {
        $id = $this->draft();
        $this->signIn($this->guest);

        try {
            $this->editing()->edit($id, $this->input());
            self::fail('A guest edited a document.');
        } catch (PurchasingNotAllowed $refused) {
            self::assertSame('Only the owners and members of the organisation work with its received documents.', $refused->getMessage());
        }
    }

    public function testEditingLocksTheFirm(): void
    {
        $this->watchFirms();
        $id = $this->draft();
        $this->firms->forget();

        $this->editing()->edit($id, $this->input());
        self::assertSame([$this->firm->id()], $this->firms->locked, 'A draft is edited under the lock of its firm.');

        $this->recording()->record($id, $this->input());
        $this->firms->forget();
        $this->editing()->edit($id, $this->input(supplierNumber: 'FV-2'));
        self::assertSame([$this->firm->id()], $this->firms->locked, 'So is a recorded document.');
    }

    public function testRecordingLocksTheFirm(): void
    {
        $this->watchFirms();
        $draft = $this->draft();
        $this->firms->forget();

        $this->recording()->record($draft, $this->input());
        self::assertSame([$this->firm->id()], $this->firms->locked);

        $this->firms->forget();
        $this->recording()->create($this->input(supplierNumber: 'FV-2'));
        self::assertSame([$this->firm->id()], $this->firms->locked);
    }

    public function testUploadingDoesNotLockTheFirm(): void
    {
        $this->watchFirms();

        $this->uploads()->upload([$this->file()]);

        self::assertSame([], $this->firms->locked);
    }

    public function testNothingIsUploadedBeforeTheFirmExistsWhateverTheFiles(): void
    {
        $this->em->createQuery('DELETE FROM '.LegalEntity::class)->execute();

        $this->expectException(NoFirmYet::class);

        $this->uploads()->upload([]);
    }

    public function testADocumentInTheTrashIsRefusedBeforeItsInputIsRead(): void
    {
        $id = $this->draft();
        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Not ours');

        $this->assertRefused(ReceivedDocumentRefused::TRASHED, fn (): SavedReceivedDocument => $this->editing()->edit($id, $this->input(supplierId: Id::generate())));
    }

    public function testARecordedDocumentTakesNoRateItCannotGet(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $this->assertRefused(ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE, fn (): SavedReceivedDocument => $this->editing()->edit($id, $this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::NonEuService, currency: Currency::USD, base: 10_000)));
        self::assertSame(Currency::CZK, $this->document($id)->currency());
    }

    public function testADocumentCreatedAtOnceTakesNoRateItCannotGet(): void
    {
        $this->assertRefused(ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE, fn (): SavedReceivedDocument => $this->recording()->create($this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::NonEuService, currency: Currency::USD, base: 10_000)));
    }

    public function testAnOriginalThatDoesNotExistIsRefusedWhenEditing(): void
    {
        $id = $this->draft();

        $this->assertRefused(ReceivedDocumentRefused::ORIGINAL_INVALID, fn (): SavedReceivedDocument => $this->editing()->edit($id, $this->input(kind: ReceivedDocumentKind::CreditNote, base: -10_000, originalDocumentId: Id::generate())));
    }

    public function testADocumentCannotBeItsOwnOriginal(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $this->assertRefused(ReceivedDocumentRefused::ORIGINAL_INVALID, fn (): SavedReceivedDocument => $this->editing()->edit($id, $this->input(kind: ReceivedDocumentKind::CreditNote, base: -10_000, originalDocumentId: $id)));
    }

    public function testOnlyADocumentInTheTrashIsHeldByTheLockOnRestore(): void
    {
        $lock = new class implements DocumentLock {
            public bool $all = false;

            public function isLocked(ReceivedDocument $document): bool
            {
                return $this->all;
            }
        };
        self::getContainer()->set(DocumentLock::class, $lock);
        $inTrash = $this->recording()->record($this->draft(), $this->input())->document->id();
        self::getContainer()->get(TrashReceivedDocument::class)->trash($inTrash, 'Recorded twice');
        $outside = $this->draft('%PDF-1.4 another');
        $lock->all = true;

        $this->assertRefused(ReceivedDocumentRefused::LOCKED, fn (): ReceivedDocument => self::getContainer()->get(RestoreReceivedDocument::class)->restore($inTrash));
        self::assertTrue($this->document($inTrash)->isTrashed());
        self::assertSame($outside, self::getContainer()->get(RestoreReceivedDocument::class)->restore($outside)->id(), 'A locked document outside the trash is left as it is.');
    }

    public function testEveryFileOfADraftGoesWithIt(): void
    {
        $id = $this->draft();
        self::getContainer()->get(UploadAttachment::class)->upload(ReceivedDocument::ATTACHMENT_KIND, $id, $this->file('%PDF-1.4 a second file'));
        $files = [];
        foreach ($this->em->getRepository(Attachment::class)->findAll() as $attachment) {
            $files[] = self::getContainer()->getParameter('app.attachments_dir').'/'.$attachment->storageKey();
        }
        self::assertCount(2, $files);
        foreach ($files as $file) {
            self::assertFileExists($file);
        }

        self::getContainer()->get(DeleteReceivedDocumentDraft::class)->delete($id);

        foreach ($files as $file) {
            self::assertFileDoesNotExist($file);
        }
    }

    /**
     * The firms as they run, with their locks remembered.
     */
    private function watchFirms(): void
    {
        $this->firms = new WatchedLegalEntities(new DoctrineLegalEntities($this->em));
        self::getContainer()->set(LegalEntities::class, $this->firms);
    }
}

/**
 * The firms as they run, remembering which was locked.
 */
final class WatchedLegalEntities implements LegalEntities
{
    /** @var list<string> */
    public array $locked = [];

    public function __construct(private readonly LegalEntities $real)
    {
    }

    public function forget(): void
    {
        $this->locked = [];
    }

    public function all(): array
    {
        return $this->real->all();
    }

    public function get(string $id): LegalEntity
    {
        return $this->real->get($id);
    }

    public function lock(string $id): LegalEntity
    {
        $this->locked[] = $id;

        return $this->real->lock($id);
    }

    public function add(LegalEntity $firm): void
    {
        $this->real->add($firm);
    }
}
