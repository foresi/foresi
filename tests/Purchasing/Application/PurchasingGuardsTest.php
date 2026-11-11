<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application;

use App\Attachment\Application\UploadAttachment;
use App\Attachment\Domain\Attachment;
use App\Audit\Application\AuditCorrelation;
use App\Audit\Domain\AuditEntry;
use App\Party\Application\LegalEntities;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use App\Party\Infrastructure\Doctrine\DoctrineLegalEntities;
use App\Purchasing\Application\DeleteReceivedDocumentDraft;
use App\Purchasing\Application\DocumentLock;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\RestoreReceivedDocument;
use App\Purchasing\Application\SavedReceivedDocument;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;

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
        self::assertSame([], $this->firms->read);

        $this->recording()->record($id, $this->input());
        $this->firms->forget();
        $this->editing()->edit($id, $this->input(supplierNumber: 'FV-2'));
        self::assertSame([$this->firm->id()], $this->firms->locked, 'So is a recorded document.');
        self::assertSame([], $this->firms->read);
    }

    public function testRecordingLocksTheFirm(): void
    {
        $this->watchFirms();
        $draft = $this->draft();
        $this->firms->forget();

        $this->recording()->record($draft, $this->input());
        self::assertSame([$this->firm->id()], $this->firms->locked);
        self::assertSame([], $this->firms->read);

        $this->firms->forget();
        $this->recording()->create($this->firm->id(), $this->input(supplierNumber: 'FV-2'));
        self::assertSame([$this->firm->id()], $this->firms->locked);
        self::assertSame([], $this->firms->read);
    }

    public function testUploadingReadsTheFirmWithoutALock(): void
    {
        $this->watchFirms();

        $this->uploads()->upload($this->firm->id(), [$this->file()]);

        self::assertSame([], $this->firms->locked);
        self::assertContains($this->firm->id(), $this->firms->read);
    }

    public function testNothingIsUploadedToAnArchivedFirmWhateverTheFiles(): void
    {
        $this->firm->archive(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        $this->assertRefused(ReceivedDocumentRefused::FIRM_ARCHIVED, fn (): array => $this->uploads()->upload($this->firm->id(), []));
    }

    public function testNothingIsUploadedToAnUnknownFirmWhateverTheFiles(): void
    {
        $this->expectException(UnknownLegalEntity::class);

        $this->uploads()->upload(Id::generate(), []);
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
        $this->assertRefused(ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE, fn (): SavedReceivedDocument => $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::NonEuService, currency: Currency::USD, base: 10_000)));
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
     * The firms as they run, told apart by how they are asked for.
     */
    private function watchFirms(): void
    {
        $this->firms = new WatchedLegalEntities(new DoctrineLegalEntities($this->em, self::getContainer()->get(TenantContext::class)));
        self::getContainer()->set(LegalEntities::class, $this->firms);
    }
}

/**
 * The firms as they run, remembering how each was asked for.
 */
final class WatchedLegalEntities implements LegalEntities
{
    /** @var list<string> */
    public array $locked = [];
    /** @var list<string> */
    public array $read = [];

    public function __construct(private readonly LegalEntities $real)
    {
    }

    public function forget(): void
    {
        $this->locked = [];
        $this->read = [];
    }

    public function all(): array
    {
        return $this->real->all();
    }

    public function active(): array
    {
        return $this->real->active();
    }

    public function get(string $id): LegalEntity
    {
        $this->read[] = $id;

        return $this->real->get($id);
    }

    public function lock(string $id): LegalEntity
    {
        $this->locked[] = $id;

        return $this->real->lock($id);
    }

    public function lockActive(): array
    {
        return $this->real->lockActive();
    }

    public function add(LegalEntity $firm): void
    {
        $this->real->add($firm);
    }
}
