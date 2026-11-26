<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Application;

use Foresi\DocumentTemplates\Application\Api\AddSignerInput;
use Foresi\DocumentTemplates\Application\Api\ArchiveDocumentTemplateInput;
use Foresi\DocumentTemplates\Application\Api\ArchiveDocumentTypeInput;
use Foresi\DocumentTemplates\Application\Api\AttachSignedCopyInput;
use Foresi\DocumentTemplates\Application\Api\CancelDocumentInput;
use Foresi\DocumentTemplates\Application\Api\CreateDocumentInput;
use Foresi\DocumentTemplates\Application\Api\CreateDocumentTemplateInput;
use Foresi\DocumentTemplates\Application\Api\CreateDocumentTypeInput;
use Foresi\DocumentTemplates\Application\Api\GenerateDocumentAgainInput;
use Foresi\DocumentTemplates\Application\Api\GenerateDocumentInput;
use Foresi\DocumentTemplates\Application\Api\MarkDocumentSentInput;
use Foresi\DocumentTemplates\Application\Api\MarkSignerDeclinedInput;
use Foresi\DocumentTemplates\Application\Api\MarkSignerSignedInput;
use Foresi\DocumentTemplates\Application\Api\RemoveSignerInput;
use Foresi\DocumentTemplates\Application\Api\SaveTemplateVersionInput;
use Foresi\DocumentTemplates\Application\Api\UpdateDocumentInput;
use Foresi\DocumentTemplates\Application\Api\UpdateDocumentTypeInput;
use Foresi\DocumentTemplates\Application\Api\UpdateSignerInput;
use Foresi\DocumentTemplates\Application\DocumentFiles;
use Foresi\DocumentTemplates\Application\DocumentFilter;
use Foresi\DocumentTemplates\Application\DocumentReads;
use Foresi\DocumentTemplates\Application\DocumentsAccess;
use Foresi\DocumentTemplates\Application\DocumentsRefused;
use Foresi\DocumentTemplates\Application\DocumentsTransaction;
use Foresi\DocumentTemplates\Application\DocumentTypeWrites;
use Foresi\DocumentTemplates\Application\DocumentVariables;
use Foresi\DocumentTemplates\Application\LinkTargets;
use Foresi\DocumentTemplates\Application\StoredFile;
use Foresi\DocumentTemplates\Application\TemplateRenderer;
use Foresi\DocumentTemplates\Application\TemplateWrites;
use Foresi\DocumentTemplates\Contract\DocumentLinkTarget;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\DocumentTemplates\Support\DocumentsTestCase;
use Foresi\Tests\DocumentTemplates\Support\FakeClientVariables;
use Foresi\Tests\DocumentTemplates\Support\ForgetfulStore;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every use of documents checks before it acts: who acts, that the
 * records exist, and what is left out of an input.
 */
final class DocumentsGuardsTest extends DocumentsTestCase
{
    #[Test]
    public function whoIsNotOfTheOrganisationIsRefusedEverything(): void
    {
        $document = $this->draft();
        $signer = $this->signer($document, 'Jana Example', 1);
        // Signed in, but no longer of the organisation, as a guest is not.
        $stranger = new User('stranger@example.com', 'hash', 'Example Stranger');
        $this->em->persist($stranger);
        $this->em->flush();
        $this->signIn(new Membership($stranger, Role::Member));
        $reads = $this->reads();
        $types = $this->types();
        $templates = $this->templates();
        $writes = $this->documents();
        $documentId = $document->id();
        $signerId = $signer->id();
        $pdf = tempnam(sys_get_temp_dir(), 'signed');
        file_put_contents($pdf, self::PDF);

        $calls = [
            'types' => static fn (): mixed => $reads->types(),
            'templates' => static fn (): mixed => $reads->templates(),
            'template' => static fn (): mixed => $reads->template($document->templateId()),
            'documents' => static fn (): mixed => $reads->documents(),
            'document' => static fn (): mixed => $reads->document($documentId),
            'create type' => static fn (): mixed => $types->create(new CreateDocumentTypeInput('Offer', 'offer')),
            'update type' => static fn (): mixed => $types->update(new UpdateDocumentTypeInput($document->typeId(), 'Offer', true, 'simple')),
            'archive type' => static fn (): mixed => $types->archive(new ArchiveDocumentTypeInput($document->typeId())),
            'create template' => static fn (): mixed => $templates->create(new CreateDocumentTemplateInput($document->typeId(), 'Offer', '<p>Offer</p>')),
            'save version' => static fn (): mixed => $templates->saveVersion(new SaveTemplateVersionInput($document->templateId(), '<p>Offer</p>')),
            'archive template' => static fn (): mixed => $templates->archive(new ArchiveDocumentTemplateInput($document->templateId())),
            'create' => static fn (): mixed => $writes->create(new CreateDocumentInput($document->typeId(), $document->templateId(), 'Offer')),
            'update' => static fn (): mixed => $writes->update(new UpdateDocumentInput($documentId, 'Offer')),
            'generate' => static fn (): mixed => $writes->generate(new GenerateDocumentInput($documentId)),
            'mark sent' => static fn (): mixed => $writes->markSent(new MarkDocumentSentInput($documentId)),
            'attach copy' => static fn (): mixed => $writes->attachSignedCopy(new AttachSignedCopyInput($documentId, 'signed.pdf', base64_encode(self::PDF))),
            'attach file' => static fn (): mixed => $writes->attachSignedFile($documentId, 'signed.pdf', (string) $pdf),
            'cancel' => static fn (): mixed => $writes->cancel(new CancelDocumentInput($documentId)),
            'generate again' => static fn (): mixed => $writes->generateAgain(new GenerateDocumentAgainInput($documentId)),
            'add signer' => static fn (): mixed => $writes->addSigner(new AddSignerInput($documentId, 'Petr Example', 'ours')),
            'update signer' => static fn (): mixed => $writes->updateSigner(new UpdateSignerInput($documentId, $signerId, 'Petr Example', 'ours')),
            'remove signer' => static fn (): mixed => $writes->removeSigner(new RemoveSignerInput($documentId, $signerId)),
            'mark signed' => static fn (): mixed => $writes->markSigned(new MarkSignerSignedInput($documentId, $signerId)),
            'mark declined' => static fn (): mixed => $writes->markDeclined(new MarkSignerDeclinedInput($documentId, $signerId)),
        ];
        foreach ($calls as $name => $call) {
            self::assertSame('forbidden', $this->reason($call), $name);
        }
        unlink((string) $pdf);
    }

    #[Test]
    public function aRecordThatIsNotThereIsNotFound(): void
    {
        $document = $this->draft();
        $unknown = Id::generate();

        self::assertSame('not_found', $this->reason(fn (): mixed => $this->reads()->template($unknown)));
        self::assertSame('not_found', $this->reason(fn (): mixed => $this->types()->update(new UpdateDocumentTypeInput($unknown, 'Offer', true, 'simple'))));
        self::assertSame('not_found', $this->reason(fn (): mixed => $this->templates()->saveVersion(new SaveTemplateVersionInput($unknown, '<p>Offer</p>'))));
        self::assertSame('not_found', $this->reason(fn (): mixed => $this->documents()->cancel(new CancelDocumentInput($unknown))));
        self::assertSame('not_found', $this->reason(fn (): mixed => $this->documents()->removeSigner(new RemoveSignerInput($document->id(), $unknown))));
        self::assertSame('not_found', $this->reason(fn (): mixed => $this->reads()->fileOf($document->id(), 'certificate')));
        self::assertSame('signature_level_unknown', $this->reason(fn (): mixed => $this->types()->create(new CreateDocumentTypeInput('Offer', 'offer', true, 'notarised'))));
        self::assertSame('paper_unknown', $this->reason(fn (): mixed => $this->templates()->create(new CreateDocumentTemplateInput($document->typeId(), 'Offer', '<p>Offer</p>', 'a3'))));
        self::assertSame('side_unknown', $this->reason(fn (): mixed => $this->documents()->addSigner(new AddSignerInput($document->id(), 'Jana Example', 'both'))));
    }

    #[Test]
    public function anArchivedTypeTakesNoTemplatesAndNoDocuments(): void
    {
        $version = $this->template();
        $template = $this->store()->template($version->templateId());
        self::assertNotNull($template);
        $other = $this->template();
        $this->types()->archive(new ArchiveDocumentTypeInput($template->typeId()));

        self::assertSame('type_unknown', $this->reason(fn (): mixed => $this->templates()->create(new CreateDocumentTemplateInput($template->typeId(), 'Offer', '<p>Offer</p>'))));
        self::assertSame('type_unknown', $this->reason(fn (): mixed => $this->documents()->create(new CreateDocumentInput($template->typeId(), $template->id(), 'Offer'))));
        self::assertSame('template_unknown', $this->reason(fn (): mixed => $this->documents()->create(new CreateDocumentInput($this->store()->template($other->templateId())?->typeId() ?? '', $template->id(), 'Offer'))));
    }

    #[Test]
    public function anArchivedTemplateTakesNoDocuments(): void
    {
        $version = $this->template();
        $template = $this->store()->template($version->templateId());
        self::assertNotNull($template);
        $this->templates()->archive(new ArchiveDocumentTemplateInput($template->id()));

        self::assertSame('template_unknown', $this->reason(fn (): mixed => $this->documents()->create(new CreateDocumentInput($template->typeId(), $template->id(), 'Offer'))));
    }

    #[Test]
    public function aTemplateIsCheckedWhenItIsCreated(): void
    {
        $version = $this->template();
        $template = $this->store()->template($version->templateId());
        self::assertNotNull($template);

        self::assertSame('template_not_allowed', $this->reason(fn (): mixed => $this->templates()->create(new CreateDocumentTemplateInput($template->typeId(), 'Offer', '{{ _self }}'))));
    }

    #[Test]
    public function inputsLeftOutTakeTheirDefaults(): void
    {
        $template = new CreateDocumentTemplateInput('type', 'Offer', '<p>Offer</p>');
        $version = new SaveTemplateVersionInput('template', '<p>Offer</p>');
        foreach ([$template, $version] as $input) {
            self::assertSame(['a4', false, 20, 20, 20, 20], [$input->paper, $input->landscape, $input->marginTop, $input->marginRight, $input->marginBottom, $input->marginLeft]);
        }
        self::assertSame(1, new AddSignerInput('document', 'Jana Example', 'theirs')->order);
        $filter = new DocumentFilter();
        self::assertSame([50, 0], [$filter->limit, $filter->offset]);
    }

    #[Test]
    public function listsLeaveTheArchivedOutAndPageByFifty(): void
    {
        $version = $this->template();
        $template = $this->store()->template($version->templateId());
        self::assertNotNull($template);
        $kept = $this->template();
        $this->types()->archive(new ArchiveDocumentTypeInput($template->typeId()));
        $this->templates()->archive(new ArchiveDocumentTemplateInput($template->id()));
        $reads = $this->reads();

        self::assertNotContains($template->typeId(), array_map(static fn (\Foresi\DocumentTemplates\Domain\DocumentType $type): string => $type->id(), $reads->types()));
        self::assertSame([$kept->templateId()], array_map(static fn (\Foresi\DocumentTemplates\Domain\DocumentTemplate $one): string => $one->id(), $reads->templates()));

        $keptTemplate = $this->store()->template($kept->templateId());
        self::assertNotNull($keptTemplate);
        for ($i = 0; $i < 51; ++$i) {
            $this->documents()->create(new CreateDocumentInput($keptTemplate->typeId(), $keptTemplate->id(), 'Offer '.$i));
        }
        self::assertCount(50, $reads->documents());
        self::assertCount(1, $reads->documents(limit: 1));
        self::assertCount(51, $reads->documents(limit: DocumentFilter::MAX_LIMIT));
    }

    #[Test]
    public function linksAreCheckedOneByOne(): void
    {
        $other = Id::generate();
        $this->contacts->add($other, 'Other Client Ltd');
        $targets = new LinkTargets([$this->contacts]);

        self::assertSame([['kind' => 'contact', 'id' => $this->contactId], ['kind' => 'contact', 'id' => $other]], $targets->check([['kind' => 'contact', 'id' => $this->contactId], ['kind' => 'contact', 'id' => $other]]));
        self::assertSame('link_invalid', $this->reason(static fn (): mixed => $targets->check([['kind' => 7, 'id' => Id::generate()]])));
        self::assertSame('link_invalid', $this->reason(static fn (): mixed => $targets->check([['kind' => 'contact', 'id' => 7]])));
        try {
            $targets->check([['kind' => 'contact', 'id' => $other], 'not a link']);
            self::fail('A link that is not an object was kept.');
        } catch (DocumentsRefused $refused) {
            self::assertStringStartsWith('Link 2 is not', $refused->getMessage());
        }

        $missing = Id::generate();
        try {
            $targets->check([['kind' => 'contact', 'id' => $missing]]);
            self::fail('A link to a record that is not there was kept.');
        } catch (DocumentsRefused $refused) {
            self::assertSame(['kind' => 'contact', 'id' => $missing], $refused->details());
        }
        try {
            new LinkTargets([])->check([['kind' => 'contact', 'id' => $missing]]);
            self::fail('A link was kept with no module offering its kind.');
        } catch (DocumentsRefused $refused) {
            self::assertStringEndsWith('the kinds offered are: none.', $refused->getMessage());
        }
    }

    #[Test]
    public function theKindsOfferedAreSortedOnce(): void
    {
        $deals = new class implements DocumentLinkTarget {
            public function kinds(): array
            {
                return ['deal', 'contact'];
            }

            public function exists(string $kind, string $id): bool
            {
                return false;
            }

            public function label(string $kind, string $id): ?string
            {
                return null;
            }

            public function url(string $kind, string $id): ?string
            {
                return null;
            }
        };

        self::assertSame(['contact', 'deal'], new LinkTargets([$deals, $this->contacts])->kinds());
    }

    #[Test]
    public function theVariablesComeByPrefixWithTheirExamples(): void
    {
        $variables = new DocumentVariables([$this->firm, new FakeClientVariables($this->contacts)]);

        self::assertSame(['client', 'firm'], array_keys($variables->definitions()));
        self::assertSame(['client' => ['name' => 'Example Client Ltd'], 'firm' => ['name' => 'Example Firm s.r.o.']], $variables->examples());
    }

    #[Test]
    public function eachStepIsRecordedOnce(): void
    {
        $document = $this->draft();
        self::assertCount(1, $this->eventData('documents.document.created'));
        $signer = $this->signer($document, 'Jana Example', 1);
        $this->documents()->generate(new GenerateDocumentInput($document->id()));

        $sent = [];
        foreach ([1, 2] as $time) {
            $this->documents()->markSent(new MarkDocumentSentInput($document->id()));
            $sent[$time] = \count($this->eventData('documents.document.sent_for_signature'));
        }
        self::assertSame([1 => 1, 2 => 1], $sent);

        $this->documents()->cancel(new CancelDocumentInput($document->id()));
        $this->documents()->generateAgain(new GenerateDocumentAgainInput($document->id()));
        self::assertCount(2, $this->eventData('documents.document.created'));

        $again = $this->draft();
        $this->documents()->generate(new GenerateDocumentInput($again->id()));
        $other = $this->signer($again, 'Petr Example', 1);
        $this->documents()->markSigned(new MarkSignerSignedInput($again->id(), $other->id()));
        self::assertCount(2, $this->eventData('documents.document.sent_for_signature'));
        self::assertSame($signer->documentId(), $document->id());
    }

    #[Test]
    public function aClosedDocumentKeepsItsSigners(): void
    {
        $document = $this->draft();
        $signer = $this->signer($document, 'Jana Example', 1);
        $this->documents()->cancel(new CancelDocumentInput($document->id()));

        self::assertSame('final', $this->reason(fn (): mixed => $this->documents()->addSigner(new AddSignerInput($document->id(), 'Petr Example', 'ours'))));
        self::assertSame('final', $this->reason(fn (): mixed => $this->documents()->updateSigner(new UpdateSignerInput($document->id(), $signer->id(), 'Petr Example', 'ours'))));
    }

    #[Test]
    public function filesAreNamedAfterTheTitleOrTheUpload(): void
    {
        $files = new class implements DocumentFiles {
            /** @var list<string> */
            public array $names = [];

            public function store(string $documentId, string $fileName, string $bytes): StoredFile
            {
                $this->names[] = $fileName;

                return new StoredFile(Id::generate(), hash('sha256', $bytes));
            }
        };
        $writes = $this->documents($files);
        $version = $this->template('<p>Offer</p>');
        $template = $this->store()->template($version->templateId());
        self::assertNotNull($template);
        $titles = ['(Draft) Smlouva o dilo!', '!!!', str_repeat('Long title ', 10)];
        foreach ($titles as $title) {
            $document = $writes->create(new CreateDocumentInput($template->typeId(), $template->id(), $title));
            $writes->generate(new GenerateDocumentInput($document->id()));
            $writes->attachSignedCopy(new AttachSignedCopyInput($document->id(), '' === trim($title, '!') ? 'Signed Scan.PDF' : ' ', base64_encode(self::PDF)));
        }

        self::assertSame([
            'draft-smlouva-o-dilo.pdf',
            'draft-smlouva-o-dilo.pdf',
            'document.pdf',
            'signed-scan.pdf',
            substr(rtrim(str_repeat('long-title-', 10), '-'), 0, 80).'.pdf',
            substr(rtrim(str_repeat('long-title-', 10), '-'), 0, 80).'.pdf',
        ], $files->names);
    }

    #[Test]
    public function aSignedCopyOfExactlyTenMegabytesIsTaken(): void
    {
        $document = $this->draft();
        $this->documents()->generate(new GenerateDocumentInput($document->id()));
        $bytes = str_pad(self::PDF, 10 * 1024 * 1024, ' ');
        $path = tempnam(sys_get_temp_dir(), 'signed');
        file_put_contents($path, $bytes);

        $this->documents()->attachSignedFile($document->id(), 'signed.pdf', (string) $path);
        unlink((string) $path);
        self::assertNotNull($this->reload($document)->signedAttachmentId());

        $other = $this->draft();
        $this->documents()->generate(new GenerateDocumentInput($other->id()));
        $this->documents()->attachSignedCopy(new AttachSignedCopyInput($other->id(), 'signed.pdf', base64_encode($bytes)));
        self::assertNotNull($this->reload($other)->signedAttachmentId());
        self::assertSame('file_invalid', $this->reason(fn (): mixed => $this->documents()->attachSignedFile($other->id(), 'signed.pdf', sys_get_temp_dir().'/no-such-file.pdf')));
    }

    #[Test]
    public function aTemplateWithoutVersionsOrADocumentWithoutItsTypeIsABug(): void
    {
        $document = $this->draft();
        $container = self::getContainer();
        $noVersions = new ForgetfulStore($this->store(), false, true);
        $noTypes = new ForgetfulStore($this->store(), true, false);
        $templates = new TemplateWrites($container->get(DocumentsAccess::class), $noVersions, $container->get(DocumentsTransaction::class), new TemplateRenderer(), $this->clock);

        $this->assertBug(fn (): mixed => $this->documents(null, $noVersions)->generate(new GenerateDocumentInput($document->id())));
        $this->assertBug(static fn (): mixed => $templates->saveVersion(new SaveTemplateVersionInput($document->templateId(), '<p>Offer</p>')));
        $this->assertBug(fn (): mixed => $this->documents(null, $noTypes)->cancel(new CancelDocumentInput($document->id())));
        $this->assertBug(fn (): mixed => $this->documents(null, $noTypes)->addSigner(new AddSignerInput($document->id(), 'Jana Example', 'theirs')));
    }

    private function assertBug(callable $call): void
    {
        try {
            $call();
            self::fail('Data that cannot be was taken as it is.');
        } catch (\LogicException $bug) {
            self::assertStringStartsWith('A ', $bug->getMessage());
        }
    }

    /**
     * @return string the reason of the refusal
     */
    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (DocumentsRefused $refused) {
            return $refused->reason();
        }
        self::fail('The call was not refused.');
    }

    private function reads(): DocumentReads
    {
        return self::getContainer()->get(DocumentReads::class);
    }

    private function types(): DocumentTypeWrites
    {
        return self::getContainer()->get(DocumentTypeWrites::class);
    }
}
