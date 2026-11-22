<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Application;

use Foresi\Documents\Application\Api\GenerateDocumentInput;
use Foresi\Documents\Application\Api\SaveTemplateVersionInput;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Tests\Documents\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TemplateVersionTest extends DocumentsTestCase
{
    #[Test]
    public function versionEditsInPlaceUntilReferenced(): void
    {
        $first = $this->template('<p>One</p>');

        $saved = $this->templates()->saveVersion(new SaveTemplateVersionInput($first->templateId(), '<p>Two</p>', 'letter', true, 10, 11, 12, 13));

        self::assertSame($first->id(), $saved->id());
        self::assertSame(1, $saved->number());
        self::assertSame('<p>Two</p>', $saved->body());
        self::assertTrue($saved->page()->landscape);
        self::assertSame(13.0, $saved->page()->marginLeftMm);
        self::assertCount(1, $this->store()->versions($first->templateId()));
    }

    #[Test]
    public function savingFrozenVersionCreatesNextNumber(): void
    {
        $first = $this->template('<p>{{ client.name }}</p>');
        $this->documents()->generate(new GenerateDocumentInput($this->draft($first)->id()));

        $saved = $this->templates()->saveVersion(new SaveTemplateVersionInput($first->templateId(), '<p>Changed {{ client.name }}</p>'));

        self::assertNotSame($first->id(), $saved->id());
        self::assertSame(2, $saved->number());
        $this->em->clear();
        $kept = $this->store()->version($first->id());
        self::assertNotNull($kept);
        self::assertTrue($kept->isFrozen());
        self::assertSame('<p>{{ client.name }}</p>', $kept->body());
        // The next save changes version 2 in place, as nothing was generated from it.
        self::assertSame($saved->id(), $this->templates()->saveVersion(new SaveTemplateVersionInput($first->templateId(), '<p>Again</p>'))->id());
    }

    #[Test]
    public function aBodyOutsideTheSandboxIsNeverSaved(): void
    {
        $first = $this->template('<p>One</p>');

        try {
            $this->templates()->saveVersion(new SaveTemplateVersionInput($first->templateId(), '{% include "x" %}'));
            self::fail('A template outside the sandbox was saved.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('template_not_allowed', $refused->reason());
        }
        $this->em->clear();
        self::assertSame('<p>One</p>', $this->store()->version($first->id())?->body());
    }

    #[Test]
    public function marginsOutsideThePageAreRefused(): void
    {
        $first = $this->template('<p>One</p>');

        $this->expectException(DocumentsRefused::class);

        $this->templates()->saveVersion(new SaveTemplateVersionInput($first->templateId(), '<p>Two</p>', 'a4', false, 70));
    }
}
