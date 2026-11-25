<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Application\Api\AddSignerInput;
use Foresi\Documents\Application\Api\AttachSignedCopyInput;
use Foresi\Documents\Application\Api\CancelDocumentInput;
use Foresi\Documents\Application\Api\CreateDocumentInput;
use Foresi\Documents\Application\Api\GenerateDocumentAgainInput;
use Foresi\Documents\Application\Api\GenerateDocumentInput;
use Foresi\Documents\Application\Api\MarkDocumentSentInput;
use Foresi\Documents\Application\Api\MarkSignerDeclinedInput;
use Foresi\Documents\Application\Api\MarkSignerSignedInput;
use Foresi\Documents\Application\Api\RemoveSignerInput;
use Foresi\Documents\Application\Api\RestoreDocumentInput;
use Foresi\Documents\Application\Api\TrashDocumentInput;
use Foresi\Documents\Application\Api\UpdateDocumentInput;
use Foresi\Documents\Application\Api\UpdateSignerInput;
use Foresi\Documents\Contract\Event\DocumentCancelled;
use Foresi\Documents\Contract\Event\DocumentCompleted;
use Foresi\Documents\Contract\Event\DocumentCreated;
use Foresi\Documents\Contract\Event\DocumentDeclined;
use Foresi\Documents\Contract\Event\DocumentGenerated;
use Foresi\Documents\Contract\Event\DocumentRestored;
use Foresi\Documents\Contract\Event\DocumentSentForSignature;
use Foresi\Documents\Contract\Event\DocumentTrashed;
use Foresi\Documents\Contract\Event\SignerSigned;
use Foresi\Documents\Contract\RecordLink;
use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentLink;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\Signer;
use Foresi\Documents\Domain\SignerSide;
use Foresi\Documents\Domain\SignerStatus;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Contract\Operation\Omitted;
use Psr\Clock\ClockInterface;

/**
 * The life of a document: create, generate, send, sign manually, complete,
 * decline, cancel, generate again, and put a cancelled one in the trash and
 * bring it back. A document completes exactly once, after
 * whichever of its last signature and its signed copy comes second.
 */
final readonly class DocumentWrites
{
    /** The largest signed copy, in bytes. */
    public const int MAX_SIGNED_COPY = 10 * 1024 * 1024;

    public function __construct(
        private DocumentsAccess $access,
        private DocumentsStore $store,
        private DocumentsTransaction $transaction,
        private LinkTargets $targets,
        private DocumentVariables $variables,
        private TemplateRenderer $renderer,
        private PdfRenderer $pdf,
        private DocumentFiles $files,
        private EventRecorder $events,
        private ClockInterface $clock,
        private string $language = 'cs',
    ) {
    }

    /**
     * @throws DocumentsRefused
     */
    public function create(CreateDocumentInput $input): Document
    {
        $userId = $this->access->denyUnlessStaff();
        $links = $this->targets->check($input->links);

        return $this->transaction->run(function () use ($input, $links, $userId): Document {
            $type = $this->store->type($input->typeId);
            if (!$type instanceof DocumentType || $type->isArchived()) {
                throw DocumentsRefused::invalid('type_unknown', 'typeId', 'There is no active document type with this id. List them with foresi_list_document_types.');
            }
            $template = $this->store->template($input->templateId);
            if (!$template instanceof DocumentTemplate || $template->isArchived() || $template->typeId() !== $type->id()) {
                throw DocumentsRefused::invalid('template_unknown', 'templateId', 'There is no active template of this type with this id. List them with foresi_list_document_templates.');
            }
            $document = DocumentsInvalid::unless('title', fn (): Document => new Document($type, $template, $input->title, $userId, $this->clock->now()));
            $this->store->add($document);
            foreach ($links as $link) {
                $this->store->add(new DocumentLink($document, $link['kind'], $link['id']));
            }
            $this->events->record(new DocumentCreated($document->id(), $type->code(), $document->status()->value));

            return $document;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function update(UpdateDocumentInput $input): Document
    {
        $this->access->denyUnlessStaff();
        $links = $input->links instanceof Omitted ? null : $this->targets->check($input->links);

        return $this->transaction->run(function () use ($input, $links): Document {
            $document = $this->document($input->documentId);
            if (\is_string($input->title)) {
                DocumentsInvalid::unless('title', static fn () => $document->retitle($input->title));
            }
            if (null !== $links) {
                DocumentsInvalid::unless('links', static fn () => $document->checkLinksEditable());
                foreach ($this->store->links($document) as $old) {
                    $this->store->remove($old);
                }
                // Removals go first, so a link given again is added anew.
                $this->transaction->flush();
                foreach ($links as $link) {
                    $this->store->add(new DocumentLink($document, $link['kind'], $link['id']));
                }
            }

            return $document;
        });
    }

    /**
     * Fills the latest template version with the values of the linked records,
     * renders and stores the PDF, and freezes the values, the PDF and the version.
     *
     * @throws DocumentsRefused template_variables_missing, template_not_allowed, not_draft
     */
    public function generate(GenerateDocumentInput $input): Document
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Document {
            $document = $this->document($input->documentId);
            if (!$document->status()->isDraft()) {
                throw DocumentsRefused::conflict('not_draft', 'Only a draft is generated; this document already was. Cancel it and generate it again for a new one.');
            }
            $version = $this->store->latestVersion($document->templateId()) ?? throw new \LogicException('A template has at least one version.');
            $values = $this->variables->values($this->links($document));
            $html = $this->renderer->render($version->body(), $this->variables->printable($values));
            $pdf = $this->pdf->render($html, $version->page(), $document->title(), $this->language);
            $stored = $this->files->store($document->id(), $this->fileName($document->title()).'.pdf', $pdf);
            $document->generate($version, $values, $stored->attachmentId, $stored->sha256, $this->clock->now());
            $this->events->record(new DocumentGenerated($document->id(), $this->typeCode($document), $document->status()->value));

            return $document;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function markSent(MarkDocumentSentInput $input): Document
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Document {
            $document = $this->document($input->documentId);
            if (DocumentsInvalid::unless('documentId', fn (): bool => $document->markSent($this->clock->now()))) {
                $this->sent($document);
            }

            return $document;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function attachSignedCopy(AttachSignedCopyInput $input): Document
    {
        $this->access->denyUnlessStaff();
        $bytes = base64_decode($input->contentBase64, true);
        if (false === $bytes || '' === $bytes) {
            throw DocumentsRefused::invalid('file_invalid', 'contentBase64', 'The signed copy is not valid base64, or it is empty.');
        }
        if (\strlen($bytes) > self::MAX_SIGNED_COPY) {
            throw DocumentsRefused::invalid('file_too_large', 'contentBase64', 'The signed copy is larger than 10 MB.');
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw DocumentsRefused::invalid('file_not_pdf', 'contentBase64', 'The signed copy must be a PDF.');
        }

        return $this->attach($input->documentId, $input->fileName, $bytes);
    }

    /**
     * The signed copy from a file on disk, as the screen uploads it.
     *
     * @throws DocumentsRefused
     */
    public function attachSignedFile(string $documentId, string $fileName, string $path): Document
    {
        $this->access->denyUnlessStaff();
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (false === $bytes || '' === $bytes) {
            throw DocumentsRefused::invalid('file_invalid', 'file', 'The signed copy did not arrive, or it is empty.');
        }
        if (\strlen($bytes) > self::MAX_SIGNED_COPY) {
            throw DocumentsRefused::invalid('file_too_large', 'file', 'The signed copy is larger than 10 MB.');
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw DocumentsRefused::invalid('file_not_pdf', 'file', 'The signed copy must be a PDF.');
        }

        return $this->attach($documentId, $fileName, $bytes);
    }

    /**
     * @throws DocumentsRefused
     */
    public function cancel(CancelDocumentInput $input): Document
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Document {
            $document = $this->document($input->documentId);
            DocumentsInvalid::unless('documentId', fn () => $document->cancel($this->clock->now()));
            $this->events->record(new DocumentCancelled($document->id(), $this->typeCode($document), $document->status()->value));

            return $document;
        });
    }

    /**
     * Puts a cancelled document in the trash; trashing it again changes nothing and records nothing.
     *
     * @throws DocumentsRefused
     */
    public function trash(TrashDocumentInput $input): Document
    {
        $userId = $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input, $userId): Document {
            $document = $this->document($input->documentId);
            if (DocumentsInvalid::unless('documentId', fn (): bool => $document->trash($userId, $this->clock->now()))) {
                $this->events->record(new DocumentTrashed($document->id(), $this->typeCode($document), $document->status()->value));
            }

            return $document;
        });
    }

    /**
     * Brings a document back from the trash; one that is not there changes nothing and records nothing.
     *
     * @throws DocumentsRefused
     */
    public function restore(RestoreDocumentInput $input): Document
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Document {
            $document = $this->document($input->documentId);
            if ($document->restore()) {
                $this->events->record(new DocumentRestored($document->id(), $this->typeCode($document), $document->status()->value));
            }

            return $document;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function generateAgain(GenerateDocumentAgainInput $input): Document
    {
        $userId = $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input, $userId): Document {
            $document = $this->document($input->documentId);
            $copy = DocumentsInvalid::unless('documentId', fn (): Document => $document->again($userId, $this->clock->now()));
            $this->store->add($copy);
            foreach ($this->store->links($document) as $link) {
                $this->store->add(new DocumentLink($copy, $link->kind(), $link->targetId()));
            }
            foreach ($this->store->signers($document) as $signer) {
                $this->store->add($signer->copyTo($copy));
            }
            $this->events->record(new DocumentCreated($copy->id(), $this->typeCode($copy), $copy->status()->value));

            return $copy;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function addSigner(AddSignerInput $input): Signer
    {
        $this->access->denyUnlessStaff();
        $side = $this->side($input->side);

        return $this->transaction->run(function () use ($input, $side): Signer {
            $document = $this->document($input->documentId);

            DocumentsInvalid::unless('documentId', static fn () => $document->checkSignersEditable());
            if (!$this->type($document)->needsSigning()) {
                throw DocumentsRefused::conflict('signing_not_needed', 'Documents of this type are not signed, so they take no signers. Change the type to one that needs signing.');
            }
            $signer = DocumentsInvalid::unless('name', static fn (): Signer => new Signer($document, $input->name, $input->email, $input->partyPersonId, $side, $input->order));
            $this->store->add($signer);

            return $signer;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function updateSigner(UpdateSignerInput $input): Signer
    {
        $this->access->denyUnlessStaff();
        $side = $this->side($input->side);

        return $this->transaction->run(function () use ($input, $side): Signer {
            $document = $this->document($input->documentId);

            DocumentsInvalid::unless('documentId', static fn () => $document->checkSignersEditable());
            $signer = $this->signer($document, $input->signerId);
            if (SignerStatus::Pending !== $signer->status()) {
                throw DocumentsRefused::conflict('signer_not_pending', 'A signer who signed or declined cannot be changed.');
            }
            DocumentsInvalid::unless('name', static fn () => $signer->change($input->name, $input->email, $input->partyPersonId, $side, $input->order));

            return $signer;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function removeSigner(RemoveSignerInput $input): Document
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Document {
            $document = $this->document($input->documentId);
            DocumentsInvalid::unless('documentId', static fn () => $document->checkSignersEditable());
            $this->store->remove($this->signer($document, $input->signerId));

            return $document;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function markSigned(MarkSignerSignedInput $input): Document
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Document {
            $document = $this->document($input->documentId);
            $now = $this->clock->now();
            $signer = $this->signer($document, $input->signerId);
            if (DocumentsInvalid::unless('signerId', static fn (): bool => $document->markSigned($signer, $now, Signer::MANUAL))) {
                $this->sent($document);
            }
            $this->events->record(new SignerSigned($document->id(), $input->signerId, $this->typeCode($document), Signer::MANUAL));
            $this->completeIfDone($document);

            return $document;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function markDeclined(MarkSignerDeclinedInput $input): Document
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Document {
            $document = $this->document($input->documentId);
            $signer = $this->signer($document, $input->signerId);
            DocumentsInvalid::unless('signerId', fn () => $document->markDeclined($signer, $this->clock->now()));
            $this->events->record(new DocumentDeclined($document->id(), $this->typeCode($document), $document->status()->value));

            return $document;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    private function attach(string $documentId, string $fileName, string $bytes): Document
    {
        return $this->transaction->run(function () use ($documentId, $fileName, $bytes): Document {
            $document = $this->document($documentId);
            $now = $this->clock->now();
            // Checked before the file is stored, so a refused copy leaves no file behind.
            DocumentsInvalid::unless('documentId', static fn () => $document->checkSignedCopy());
            $stored = $this->files->store($document->id(), $this->fileName('' === trim($fileName) ? $document->title() : pathinfo($fileName, \PATHINFO_FILENAME)).'.pdf', $bytes);
            if (DocumentsInvalid::unless('documentId', static fn (): bool => $document->attachSignedCopy($stored->attachmentId, $stored->sha256, $now))) {
                $this->sent($document);
            }
            $this->completeIfDone($document);

            return $document;
        });
    }

    private function completeIfDone(Document $document): void
    {
        if ($document->completeIfDone($this->store->signers($document), $this->clock->now())) {
            $this->events->record(new DocumentCompleted($document->id(), $this->typeCode($document), $document->status()->value));
        }
    }

    private function sent(Document $document): void
    {
        $this->events->record(new DocumentSentForSignature($document->id(), $this->typeCode($document), $document->status()->value));
    }

    /**
     * @throws DocumentsRefused
     */
    private function document(string $id): Document
    {
        return $this->store->document($id) ?? throw DocumentsRefused::notFound('There is no document with this id. Find it with foresi_list_documents.');
    }

    /**
     * @throws DocumentsRefused not_found
     */
    private function signer(Document $document, string $signerId): Signer
    {
        return $this->store->signer($document, $signerId) ?? throw DocumentsRefused::notFound('The document has no signer with this id. Read the document for its signers.');
    }

    private function typeCode(Document $document): string
    {
        return $this->type($document)->code();
    }

    private function type(Document $document): DocumentType
    {
        return $this->store->type($document->typeId()) ?? throw new \LogicException('A document has its type.');
    }

    /**
     * @return list<RecordLink>
     */
    private function links(Document $document): array
    {
        return array_map(static fn (DocumentLink $link): RecordLink => new RecordLink($link->kind(), $link->targetId()), $this->store->links($document));
    }

    private function side(string $side): SignerSide
    {
        return SignerSide::tryFrom($side) ?? throw DocumentsRefused::invalid('side_unknown', 'side', 'The side is ours (signs for the firm) or theirs.');
    }

    /** A file name from a title: letters, digits and dashes. */
    private function fileName(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(false === $ascii ? '' : $ascii)), '-');

        return '' === $name ? 'document' : substr($name, 0, 80);
    }
}
