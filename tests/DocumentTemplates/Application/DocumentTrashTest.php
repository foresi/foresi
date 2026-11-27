<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Application;

use Foresi\DocumentTemplates\Application\Api\CancelDocumentInput;
use Foresi\DocumentTemplates\Application\Api\GenerateDocumentAgainInput;
use Foresi\DocumentTemplates\Application\Api\GenerateDocumentInput;
use Foresi\DocumentTemplates\Application\Api\RestoreDocumentInput;
use Foresi\DocumentTemplates\Application\Api\TrashDocumentInput;
use Foresi\DocumentTemplates\Application\DocumentFilter;
use Foresi\DocumentTemplates\Application\DocumentReads;
use Foresi\DocumentTemplates\Application\DocumentsRefused;
use Foresi\DocumentTemplates\Domain\Document;
use Foresi\DocumentTemplates\Domain\DocumentStatus;
use Foresi\Tests\DocumentTemplates\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A cancelled document goes to the trash and comes back: it leaves the lists
 * and the records it is linked to, and keeps its history, files and frozen
 * template version.
 */
final class DocumentTrashTest extends DocumentsTestCase
{
    #[Test]
    public function aCancelledDocumentLeavesTheListsAndKeepsItsHistory(): void
    {
        $document = $this->cancelled();

        $this->documents()->trash(new TrashDocumentInput($document->id()));

        $trashed = $this->reload($document);
        self::assertTrue($trashed->isTrashed());
        self::assertNotNull($trashed->trashedAt());
        self::assertNotNull($trashed->trashedBy());
        self::assertSame(DocumentStatus::Cancelled, $trashed->status());
        self::assertNotNull($trashed->pdfAttachmentId());
        self::assertNotNull($trashed->templateVersionId());
        self::assertTrue($this->store()->version($trashed->templateVersionId())?->isFrozen());
        self::assertCount(1, $this->store()->links($trashed));
        self::assertSame([], $this->ids(new DocumentFilter()));
        self::assertSame([], $this->ids(new DocumentFilter(linkKind: 'contact', linkId: $this->contactId)), 'It leaves the documents of its records, such as a deal.');
        self::assertSame([$document->id()], $this->ids(new DocumentFilter(trashed: true)));
        self::assertSame($document->id(), $this->reads()->document($document->id())->id(), 'Read by its id, it is shown from the trash.');
        self::assertCount(1, $this->eventData('document-templates.document.trashed'));
    }

    #[Test]
    public function trashingTwiceOrRestoringOutOfTheTrashChangesNothing(): void
    {
        $document = $this->cancelled();

        $this->documents()->restore(new RestoreDocumentInput($document->id()));
        $this->documents()->trash(new TrashDocumentInput($document->id()));
        $this->documents()->trash(new TrashDocumentInput($document->id()));

        self::assertCount(1, $this->eventData('document-templates.document.trashed'));
        self::assertSame([], $this->eventData('document-templates.document.restored'));
    }

    #[Test]
    public function aRestoredDocumentIsBackCancelledInTheLists(): void
    {
        $document = $this->cancelled();
        $this->documents()->trash(new TrashDocumentInput($document->id()));

        $this->documents()->restore(new RestoreDocumentInput($document->id()));

        $restored = $this->reload($document);
        self::assertFalse($restored->isTrashed());
        self::assertNull($restored->trashedBy());
        self::assertSame(DocumentStatus::Cancelled, $restored->status());
        self::assertSame([$document->id()], $this->ids(new DocumentFilter(linkKind: 'contact', linkId: $this->contactId)));
        self::assertSame([], $this->ids(new DocumentFilter(trashed: true)));
        self::assertCount(1, $this->eventData('document-templates.document.restored'));
    }

    #[Test]
    public function onlyACancelledDocumentGoesToTheTrash(): void
    {
        $draft = $this->draft();

        self::assertSame('not_cancelled', $this->reason(fn (): Document => $this->documents()->trash(new TrashDocumentInput($draft->id()))));
        $this->documents()->generate(new GenerateDocumentInput($draft->id()));
        self::assertSame('not_cancelled', $this->reason(fn (): Document => $this->documents()->trash(new TrashDocumentInput($draft->id()))));
        self::assertFalse($this->reload($draft)->isTrashed());
    }

    #[Test]
    public function aTrashedDocumentIsNotGeneratedAgainUntilRestored(): void
    {
        $document = $this->cancelled();
        $this->documents()->trash(new TrashDocumentInput($document->id()));

        self::assertSame('trashed', $this->reason(fn (): Document => $this->documents()->generateAgain(new GenerateDocumentAgainInput($document->id()))));

        $this->documents()->restore(new RestoreDocumentInput($document->id()));
        $copy = $this->documents()->generateAgain(new GenerateDocumentAgainInput($document->id()));
        self::assertFalse($copy->isTrashed());
    }

    private function cancelled(): Document
    {
        $draft = $this->draft();
        $this->documents()->generate(new GenerateDocumentInput($draft->id()));

        return $this->documents()->cancel(new CancelDocumentInput($draft->id()));
    }

    /**
     * @return list<string>
     */
    private function ids(DocumentFilter $filter): array
    {
        return array_map(static fn (Document $document): string => $document->id(), $this->store()->documents($filter));
    }

    private function reads(): DocumentReads
    {
        return self::getContainer()->get(DocumentReads::class);
    }

    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (DocumentsRefused $refused) {
            return $refused->reason();
        }
        self::fail('The call was not refused.');
    }
}
