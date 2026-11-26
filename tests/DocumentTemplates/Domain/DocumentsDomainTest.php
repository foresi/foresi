<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Domain;

use Foresi\DocumentTemplates\Contract\VariableDefinition;
use Foresi\DocumentTemplates\Domain\Document;
use Foresi\DocumentTemplates\Domain\DocumentConflict;
use Foresi\DocumentTemplates\Domain\DocumentStatus;
use Foresi\DocumentTemplates\Domain\DocumentTemplate;
use Foresi\DocumentTemplates\Domain\DocumentType;
use Foresi\DocumentTemplates\Domain\InvalidField;
use Foresi\DocumentTemplates\Domain\PageSettings;
use Foresi\DocumentTemplates\Domain\PaperSize;
use Foresi\DocumentTemplates\Domain\SignatureLevel;
use Foresi\DocumentTemplates\Domain\Signer;
use Foresi\DocumentTemplates\Domain\SignerSide;
use Foresi\DocumentTemplates\Domain\TemplateVersion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules the records of documents keep on their own: the lengths of names,
 * the order of the steps of signing, and what a second call leaves as it was.
 */
final class DocumentsDomainTest extends TestCase
{
    private \DateTimeImmutable $now;
    private DocumentType $type;
    private DocumentTemplate $template;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-22 10:00:00');
        $this->type = new DocumentType('Agreement', 'agreement', true, SignatureLevel::Simple, $this->now);
        $this->template = new DocumentTemplate($this->type, 'Agreement', $this->now);
    }

    #[Test]
    public function namesAreTrimmedAndCountedInCharacters(): void
    {
        $title = str_repeat('Ž', Document::MAX_TITLE);
        self::assertSame($title, new Document($this->type, $this->template, $title, 'user', $this->now)->title());
        self::assertSame('NDA', new Document($this->type, $this->template, '  NDA  ', 'user', $this->now)->title());
        $this->assertRefused(fn (): Document => new Document($this->type, $this->template, $title.'Ž', 'user', $this->now));

        $name = str_repeat('Ž', DocumentTemplate::MAX_NAME);
        self::assertSame($name, new DocumentTemplate($this->type, $name, $this->now)->name());
        self::assertSame('NDA', new DocumentTemplate($this->type, ' NDA ', $this->now)->name());
        $this->assertRefused(fn (): DocumentTemplate => new DocumentTemplate($this->type, ' ', $this->now));
        $this->assertRefused(fn (): DocumentTemplate => new DocumentTemplate($this->type, $name.'Ž', $this->now));

        $name = str_repeat('Ž', DocumentType::MAX_NAME);
        self::assertSame($name, new DocumentType($name, 'nda', true, SignatureLevel::Simple, $this->now)->name());
        self::assertSame('NDA', new DocumentType(' NDA ', 'nda', true, SignatureLevel::Simple, $this->now)->name());
        $this->assertRefused(fn (): DocumentType => new DocumentType(' ', 'nda', true, SignatureLevel::Simple, $this->now));
        $this->assertRefused(fn (): DocumentType => new DocumentType($name.'Ž', 'nda', true, SignatureLevel::Simple, $this->now));

        $document = $this->document();
        $name = str_repeat('Ž', Signer::MAX_NAME);
        self::assertSame($name, $this->signer($document, $name)->name());
        $this->assertRefused(fn (): Signer => $this->signer($document, $name.'Ž'));
    }

    #[Test]
    public function aCodeIsALowercaseIdentifierAsAWhole(): void
    {
        self::assertSame('nda_2', new DocumentType('NDA', 'nda_2', true, SignatureLevel::Simple, $this->now)->code());
        $this->assertRefused(fn (): DocumentType => new DocumentType('NDA', 'Nda', true, SignatureLevel::Simple, $this->now));
        $this->assertRefused(fn (): DocumentType => new DocumentType('NDA', 'nda-2', true, SignatureLevel::Simple, $this->now));

        self::assertSame('vat_id', new VariableDefinition('vat_id', 'VAT ID', 'CZ1')->name);
        $this->assertRefused(fn (): VariableDefinition => new VariableDefinition('Vat', 'VAT ID', 'CZ1'));
        $this->assertRefused(fn (): VariableDefinition => new VariableDefinition('vat-id', 'VAT ID', 'CZ1'));
    }

    #[Test]
    public function aSignerKeepsATrimmedAddressAndAnOrderUpTo99(): void
    {
        $document = $this->document();

        self::assertSame('jana@example.com', $this->signer($document, 'Jana Example', ' jana@example.com ')->email());
        self::assertNull($this->signer($document, 'Jana Example', '   ')->email());
        self::assertSame(99, $this->signer($document, 'Jana Example', null, 99)->order());
        $long = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 58).'.cz';
        self::assertSame($long, $this->signer($document, 'Jana Example', $long)->email());
        $this->assertRefused(fn (): Signer => $this->signer($document, 'Jana Example', 'x'.$long));
    }

    #[Test]
    public function marginsReachFromZeroTo60AndLetterHasItsOwnSize(): void
    {
        $page = new PageSettings(PaperSize::Letter, false, 0.0, PageSettings::MAX_MARGIN_MM, 0.0, PageSettings::MAX_MARGIN_MM);

        self::assertSame(0.0, $page->marginTopMm);
        self::assertFalse(new PageSettings()->landscape);
        self::assertSame(215.9, PaperSize::Letter->widthMm());
        self::assertSame(279.4, PaperSize::Letter->heightMm());
    }

    #[Test]
    public function aBodyHasTextAndAtMostItsMaximum(): void
    {
        $body = str_repeat('x', TemplateVersion::MAX_BODY);

        self::assertSame($body, new TemplateVersion($this->template, 1, $body, new PageSettings(), $this->now)->body());
        $this->assertRefused(fn (): TemplateVersion => new TemplateVersion($this->template, 1, " \n ", new PageSettings(), $this->now));
        $this->assertRefused(fn (): TemplateVersion => new TemplateVersion($this->template, 1, $body.'x', new PageSettings(), $this->now));
    }

    #[Test]
    public function aSecondArchiveOrFreezeKeepsTheFirstTime(): void
    {
        $later = $this->now->modify('+1 day');
        $version = new TemplateVersion($this->template, 1, '<p>Text</p>', new PageSettings(), $this->now);

        foreach ([$this->now, $later] as $at) {
            $this->type->archive($at);
            $this->template->archive($at);
            $version->freeze($at);
        }

        self::assertEquals($this->now, $this->type->archivedAt());
        self::assertEquals($this->now, $this->template->archivedAt());
        self::assertEquals($this->now, $version->frozenAt());
    }

    #[Test]
    public function aDocumentIsGeneratedOnceAndSentOnce(): void
    {
        $document = $this->document();
        $version = new TemplateVersion($this->template, 1, '<p>Text</p>', new PageSettings(), $this->now);
        $document->generate($version, [], 'pdf', 'sha', $this->now);

        $this->assertConflict('not_draft', fn () => $document->generate($version, [], 'pdf', 'sha', $this->now));
        self::assertTrue($document->markSent($this->now));
        self::assertFalse($document->markSent($this->now));
        self::assertSame(DocumentStatus::OutForSignature, $document->status());
        $document->attachSignedCopy('signed', 'sha', $this->now);
        $this->assertConflict('signed_copy_exists', fn (): bool => $document->attachSignedCopy('signed', 'sha', $this->now));
    }

    #[Test]
    public function aDocumentTakesOnlyItsOwnSigners(): void
    {
        $document = $this->generated();
        $stranger = $this->signer($this->generated(), 'Petr Example');

        $this->assertConflict('signer_unknown', fn (): bool => $document->markSigned($stranger, $this->now, Signer::MANUAL));
        $this->assertConflict('signer_unknown', fn () => $document->markDeclined($stranger, $this->now));
        $document->attachSignedCopy('signed', 'sha', $this->now);
        $this->assertConflict('signer_unknown', fn (): bool => $document->completeIfDone([$stranger], $this->now));
    }

    #[Test]
    public function aDraftIsNotDeclined(): void
    {
        $document = $this->document();

        $this->assertConflict('not_signable', fn () => $document->markDeclined($this->signer($document, 'Jana Example'), $this->now));
    }

    #[Test]
    public function theDomainErrorsKeepTheirMessage(): void
    {
        self::assertSame('Too long.', new InvalidField('name', 'Too long.')->getMessage());
        self::assertSame('Not now.', new DocumentConflict('final', 'Not now.')->getMessage());
    }

    private function document(): Document
    {
        return new Document($this->type, $this->template, 'NDA', 'user', $this->now);
    }

    private function generated(): Document
    {
        $document = $this->document();
        $document->generate(new TemplateVersion($this->template, 1, '<p>Text</p>', new PageSettings(), $this->now), [], 'pdf', 'sha', $this->now);

        return $document;
    }

    private function signer(Document $document, string $name, ?string $email = null, int $order = 1): Signer
    {
        return new Signer($document, $name, $email, null, SignerSide::Theirs, $order);
    }

    private function assertRefused(callable $make): void
    {
        try {
            $make();
            self::fail('An invalid value was accepted.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertConflict(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail('The change was allowed.');
        } catch (DocumentConflict $conflict) {
            self::assertSame($reason, $conflict->reason);
        }
    }
}
