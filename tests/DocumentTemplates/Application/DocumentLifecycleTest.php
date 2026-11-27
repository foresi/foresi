<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Application;

use Foresi\DocumentTemplates\Application\Api\CancelDocumentInput;
use Foresi\DocumentTemplates\Application\Api\GenerateDocumentAgainInput;
use Foresi\DocumentTemplates\Application\Api\GenerateDocumentInput;
use Foresi\DocumentTemplates\Application\Api\SaveTemplateVersionInput;
use Foresi\DocumentTemplates\Application\Api\UpdateDocumentInput;
use Foresi\DocumentTemplates\Application\DocumentsRefused;
use Foresi\DocumentTemplates\Domain\DocumentStatus;
use Foresi\DocumentTemplates\Domain\SignerStatus;
use Foresi\Tests\DocumentTemplates\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Test;

final class DocumentLifecycleTest extends DocumentsTestCase
{
    #[Test]
    public function generateFreezesValuesPdfAndVersion(): void
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
        self::assertCount(1, $this->eventData('document-templates.document.generated'));
        self::assertStringNotContainsString('Example', $this->eventData('document-templates.document.generated')[0]);
    }

    #[Test]
    public function readyDocumentCannotBeEdited(): void
    {
        $draft = $this->draft();
        $this->documents()->generate(new GenerateDocumentInput($draft->id()));

        foreach ([
            fn (): \Foresi\DocumentTemplates\Domain\Document => $this->documents()->update(new UpdateDocumentInput($draft->id(), title: 'Changed')),
            fn (): \Foresi\DocumentTemplates\Domain\Document => $this->documents()->update(new UpdateDocumentInput($draft->id(), links: [])),
            fn (): \Foresi\DocumentTemplates\Domain\Document => $this->documents()->generate(new GenerateDocumentInput($draft->id())),
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
    public function aFailedGenerationLeavesTheDraftAsItWas(): void
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
        self::assertSame([], $this->eventData('document-templates.document.generated'));
    }

    #[Test]
    public function cancelKeepsHistory(): void
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
        self::assertCount(1, $this->eventData('document-templates.document.cancelled'));
        $this->expectException(DocumentsRefused::class);
        $this->documents()->cancel(new CancelDocumentInput($draft->id()));
    }

    #[Test]
    public function generateAgainCreatesNewDraftWithSameLinksAndSigners(): void
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
        self::assertSame([['contact', $this->contactId]], array_map(static fn (\Foresi\DocumentTemplates\Domain\DocumentLink $link): array => [$link->kind(), $link->targetId()], $this->store()->links($copy)));
        self::assertSame([['Jana Example', 1, 'theirs', SignerStatus::Pending], ['Petr Example', 2, 'ours', SignerStatus::Pending]], array_map(static fn (\Foresi\DocumentTemplates\Domain\Signer $signer): array => [$signer->name(), $signer->order(), $signer->side()->value, $signer->status()], $this->store()->signers($copy)));
        self::assertSame('<p>New Example Client Ltd</p>', $this->pdf->lastHtml);
        self::assertSame(2, $this->store()->version((string) $copy->templateVersionId())?->number());
        self::assertSame(DocumentStatus::Cancelled, $this->store()->document($draft->id())?->status());
    }

    #[Test]
    public function anOpenDocumentIsNotGeneratedAgain(): void
    {
        $draft = $this->draft();

        $this->expectException(DocumentsRefused::class);

        $this->documents()->generateAgain(new GenerateDocumentAgainInput($draft->id()));
    }
}
