<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Application;

use Foresi\Documents\Application\Api\AddSignerInput;
use Foresi\Documents\Application\Api\CreateDocumentInput;
use Foresi\Documents\Application\Api\CreateDocumentTemplateInput;
use Foresi\Documents\Application\Api\CreateDocumentTypeInput;
use Foresi\Documents\Application\Api\UpdateSignerInput;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Application\DocumentTypeWrites;
use Foresi\Tests\Documents\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A refusal names the field that was wrong, so a form shows it at that field
 * and an API client knows what to change.
 */
final class FieldRefusalsTest extends DocumentsTestCase
{
    #[Test]
    public function signer_refusals_name_their_field(): void
    {
        $document = $this->draft();

        self::assertSame('name', $this->field(fn () => $this->documents()->addSigner(new AddSignerInput($document->id(), ' ', 'theirs', 1))));
        self::assertSame('email', $this->field(fn () => $this->documents()->addSigner(new AddSignerInput($document->id(), 'Jana Example', 'theirs', 1, 'not-an-address'))));
        self::assertSame('order', $this->field(fn () => $this->documents()->addSigner(new AddSignerInput($document->id(), 'Jana Example', 'theirs', 0))));
        self::assertSame('partyPersonId', $this->field(fn () => $this->documents()->addSigner(new AddSignerInput($document->id(), 'Jana Example', 'theirs', 1, null, 'not-an-id'))));

        $signer = $this->signer($document, 'Jana Example', 1);
        self::assertSame('order', $this->field(fn () => $this->documents()->updateSigner(new UpdateSignerInput($document->id(), $signer->id(), 'Jana Example', 'theirs', 100))));
    }

    #[Test]
    public function margin_refusals_name_their_margin(): void
    {
        $type = self::getContainer()->get(DocumentTypeWrites::class)->create(new CreateDocumentTypeInput('Test agreement', 'margins', true, 'simple'));

        self::assertSame('marginBottom', $this->field(fn () => $this->templates()->create(new CreateDocumentTemplateInput($type->id(), 'Test template', '<p>Text</p>', marginBottom: 500))));
        self::assertSame('marginLeft', $this->field(fn () => $this->templates()->create(new CreateDocumentTemplateInput($type->id(), 'Test template', '<p>Text</p>', marginLeft: -1))));
    }

    #[Test]
    public function a_type_without_signing_takes_no_signers(): void
    {
        $type = self::getContainer()->get(DocumentTypeWrites::class)->create(new CreateDocumentTypeInput('Price list', 'price_list', false, 'simple'));
        $version = $this->templates()->create(new CreateDocumentTemplateInput($type->id(), 'Price list', '<p>Prices</p>'));
        $document = $this->documents()->create(new CreateDocumentInput($type->id(), $version->templateId(), 'Price list 2027', []));

        try {
            $this->signer($document, 'Jana Example', 1);
            self::fail('A document of a type without signing took a signer.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('signing_not_needed', $refused->reason());
        }
        self::assertSame([], $this->store()->signers($document));
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function field(\Closure $write): ?string
    {
        try {
            $write();
        } catch (DocumentsRefused $refused) {
            return $refused->field();
        }
        self::fail('The write was not refused.');
    }
}
