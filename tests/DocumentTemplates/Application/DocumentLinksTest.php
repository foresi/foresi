<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Application;

use Foresi\DocumentTemplates\Application\Api\CreateDocumentInput;
use Foresi\DocumentTemplates\Application\Api\GenerateDocumentInput;
use Foresi\DocumentTemplates\Application\DocumentFilter;
use Foresi\DocumentTemplates\Application\DocumentsRefused;
use Foresi\DocumentTemplates\Application\LinkTargets;
use Foresi\DocumentTemplates\Contract\RecordLink;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\DocumentTemplates\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Review focus 1: a linked record that goes away leaves its documents in place.
 */
final class DocumentLinksTest extends DocumentsTestCase
{
    #[Test]
    public function unknownKindRefused(): void
    {
        try {
            $this->draft();
            $this->documents()->create(new CreateDocumentInput($this->firstTypeId(), $this->firstTemplateId(), 'Deal paper', [['kind' => 'deal', 'id' => Id::generate()]]));
            self::fail('A link to a kind no module offers was kept.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('link_kind_unknown', $refused->reason());
            self::assertSame(['kind' => 'deal'], $refused->details());
        }
    }

    #[Test]
    public function aLinkToARecordThatDoesNotExistIsRefused(): void
    {
        $this->expectException(DocumentsRefused::class);

        $this->draft(null, Id::generate());
    }

    #[Test]
    public function documentSurvivesTrashedLinkAndShowsItAsUnavailable(): void
    {
        $draft = $this->draft();
        $this->documents()->generate(new GenerateDocumentInput($draft->id()));

        $this->contacts->remove($this->contactId);

        $document = $this->reload($draft);
        self::assertSame([$document->id()], array_map(static fn (\Foresi\DocumentTemplates\Domain\Document $found): string => $found->id(), $this->store()->documents(new DocumentFilter(linkKind: 'contact', linkId: $this->contactId))));
        $view = new LinkTargets([$this->contacts])->describe(new RecordLink('contact', $this->contactId));
        self::assertFalse($view->available);
        self::assertNull($view->label);
        self::assertNull($view->url);
        self::assertSame(['client' => ['name' => 'Example Client Ltd'], 'firm' => ['name' => 'Example Firm s.r.o.']], $document->resolvedValues());
    }

    private function firstTypeId(): string
    {
        return $this->store()->templates(null, false)[0]->typeId();
    }

    private function firstTemplateId(): string
    {
        return $this->store()->templates(null, false)[0]->id();
    }
}
