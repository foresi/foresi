<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Application;

use Foresi\Documents\Application\Api\CancelDocumentInput;
use Foresi\Documents\Application\Api\GenerateDocumentAgainInput;
use Foresi\Documents\Application\Api\GenerateDocumentInput;
use Foresi\Documents\Application\Api\SaveTemplateVersionInput;
use Foresi\Documents\Application\Api\UpdateDocumentInput;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Domain\DocumentStatus;
use Foresi\Documents\Domain\SignerStatus;
use Foresi\Tests\Documents\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Test;

final class DocumentLifecycleTest extends DocumentsTestCase
{
    #[Test]
    public function generate_freezes_values_pdf_and_version(): void
    {
        $version = $this->template('<p>{{ firm.name }} and {{ client.name }}</p>');
        $draft = $this->draft($version);

        $this->documents()->generate(new GenerateDocumentInput($draft->id()));
        // The records change after generation; the document keeps what it was made from.
        $this->firm->name = 'Renamed Firm';
        $this->contacts->add($this->contactId, 'Renamed Client');

        $document = $this->reload($draft);
        self::assertSame(DocumentStatus::Ready, $document->status());
        self::assertSame(['client' => ['name' => 'Example Client Ltd'], 'firm' => ['name' => 'Example Firm s.r.o.']], $document->resolvedValues());
        self::assertSame($version->id(), $document->templateVersionId());
        self::assertTrue($this->store()->version($version->id())?->isFrozen());
        self::assertSame('<p>Example Firm s.r.o. and Example Client Ltd</p>', $this->pdf->lastHtml);
        self::assertNotNull($document->pdfAttachmentId());
        self::assertSame(hash('sha256', "%PDF-1.7\n% NDA with Example Client\n<p>Example Firm s.r.o. and Example Client Ltd</p>\n%%EOF\n"), $document->pdfSha256());
        self::assertCount(1, $this->eventData('documents.document.generated'));
        self::assertStringNotContainsString('Example', $this->eventData('documents.document.generated')[0]);
    }

    #[Test]
    public function ready_document_cannot_be_edited(): void
    {
        $draft = $this->draft();
        $this->documents()->generate(new GenerateDocumentInput($draft->id()));

        foreach ([
            fn () => $this->documents()->update(new UpdateDocumentInput($draft->id(), title: 'Changed')),
            fn () => $this->documents()->update(new UpdateDocumentInput($draft->id(), links: [])),
            fn () => $this->documents()->generate(new GenerateDocumentInput($draft->id())),
        ] as $edit) {
            try {
                $edit();
                self::fail('A ready document was edited.');
            } catch (DocumentsRefused $refused) {
                self::assertSame('not_draft', $refused->reason());
            }
        }
        self::assertSame('NDA with Example Client', $this->reload($draft)->title());
    }

    #[Test]
    public function a_failed_generation_leaves_the_draft_as_it_was(): void
    {
        $draft = $this->draft($this->template('<p>{{ deal.title }}</p>'));

        try {
            $this->documents()->generate(new GenerateDocumentInput($draft->id()));
            self::fail('A template with a missing variable was generated.');
        } catch (DocumentsRefused $refused) {
            self::assertSame(['missing' => ['deal.title']], $refused->details());
        }

        $document = $this->reload($draft);
        self::assertSame(DocumentStatus::Draft, $document->status());
        self::assertNull($document->pdfAttachmentId());
        self::assertSame([], $this->eventData('documents.document.generated'));
    }

    #[Test]
    public function cancel_keeps_history(): void
    {
        $draft = $this->draft();
        $this->signer($draft, 'Jana Example', 1);
        $this->documents()->generate(new GenerateDocumentInput($draft->id()));

        $this->documents()->cancel(new CancelDocumentInput($draft->id()));

        $document = $this->reload($draft);
        self::assertSame(DocumentStatus::Cancelled, $document->status());
        self::assertNotNull($document->pdfAttachmentId());
        self::assertNotNull($document->resolvedValues());
        self::assertCount(1, $this->store()->signers($document));
        self::assertCount(1, $this->store()->links($document));
        self::assertCount(1, $this->eventData('documents.document.cancelled'));
        $this->expectException(DocumentsRefused::class);
        $this->documents()->cancel(new CancelDocumentInput($draft->id()));
    }

    #[Test]
    public function generate_again_creates_new_draft_with_same_links_and_signers(): void
    {
        $version = $this->template('<p>{{ client.name }}</p>');
        $draft = $this->draft($version);
        $this->signer($draft, 'Jana Example', 1);
        $this->signer($draft, 'Petr Example', 2, 'ours');
        $this->documents()->generate(new GenerateDocumentInput($draft->id()));
        $this->documents()->cancel(new CancelDocumentInput($draft->id()));
        $this->templates()->saveVersion(new SaveTemplateVersionInput($version->templateId(), '<p>New {{ client.name }}</p>'));

        $again = $this->documents()->generateAgain(new GenerateDocumentAgainInput($draft->id()));
        $this->documents()->generate(new GenerateDocumentInput($again->id()));

        $copy = $this->reload($again);
        self::assertNotSame($draft->id(), $copy->id());
        self::assertSame('NDA with Example Client', $copy->title());
        self::assertSame([['contact', $this->contactId]], array_map(static fn ($link): array => [$link->kind(), $link->targetId()], $this->store()->links($copy)));
        self::assertSame([['Jana Example', 1, 'theirs', SignerStatus::Pending], ['Petr Example', 2, 'ours', SignerStatus::Pending]], array_map(static fn ($signer): array => [$signer->name(), $signer->order(), $signer->side()->value, $signer->status()], $this->store()->signers($copy)));
        self::assertSame('<p>New Example Client Ltd</p>', $this->pdf->lastHtml);
        self::assertSame(2, $this->store()->version((string) $copy->templateVersionId())?->number());
        self::assertSame(DocumentStatus::Cancelled, $this->store()->document($draft->id())?->status());
    }

    #[Test]
    public function an_open_document_is_not_generated_again(): void
    {
        $draft = $this->draft();

        $this->expectException(DocumentsRefused::class);

        $this->documents()->generateAgain(new GenerateDocumentAgainInput($draft->id()));
    }
}
