<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentStatus;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Shared\Domain\Id;

/**
 * What the REST reads and the MCP read tools of Documents answer: for the
 * owners and members only, as the writes are (ADR 0041). A list leaves out the
 * documents in the trash unless it asks for them; one document read by its id
 * is shown from the trash too, so it can be restored.
 */
final readonly class DocumentReads
{
    /** The stored files of a document, as the download names them. */
    public const array FILES = ['pdf', 'signed', 'certificate'];

    public function __construct(private DocumentsStore $store, private DocumentsAccess $access)
    {
    }

    /**
     * @return list<DocumentType> in name order
     *
     * @throws DocumentsRefused
     */
    public function types(bool $withArchived = false): array
    {
        $this->access->denyUnlessStaff();

        return $this->store->types($withArchived);
    }

    /**
     * @return list<DocumentTemplate> in name order
     *
     * @throws DocumentsRefused
     */
    public function templates(?string $typeId = null, bool $withArchived = false): array
    {
        $this->access->denyUnlessStaff();
        if (null !== $typeId && !Id::isValid($typeId)) {
            throw DocumentsRefused::invalid('type_id_invalid', 'typeId', 'type_id is the id of a document type.');
        }

        return $this->store->templates($typeId, $withArchived);
    }

    /**
     * @throws DocumentsRefused
     */
    public function template(string $id): DocumentTemplate
    {
        $this->access->denyUnlessStaff();

        return (Id::isValid($id) ? $this->store->template($id) : null) ?? throw DocumentsRefused::notFound('There is no such document template.');
    }

    /**
     * The documents of the filter, newest first: those out of the trash, or
     * with trashed those in it. The filter comes as sent, so that each wrong
     * value names its parameter.
     *
     * @return list<Document>
     *
     * @throws DocumentsRefused
     */
    public function documents(?string $typeId = null, ?string $status = null, ?string $linkedKind = null, ?string $linkedId = null, ?int $limit = null, ?int $offset = null, bool $trashed = false): array
    {
        $this->access->denyUnlessStaff();
        if (null !== $typeId && !Id::isValid($typeId)) {
            throw DocumentsRefused::invalid('type_id_invalid', 'typeId', 'type_id is the id of a document type.');
        }
        $state = null === $status ? null : (DocumentStatus::tryFrom($status) ?? throw DocumentsRefused::invalid('status_invalid', 'status', \sprintf('status is one of: %s.', implode(', ', array_column(DocumentStatus::cases(), 'value')))));
        if ((null === $linkedKind) !== (null === $linkedId)) {
            throw DocumentsRefused::invalid('link_incomplete', null === $linkedKind ? 'linkedKind' : 'linkedId', 'linked_kind and linked_id go together: the kind and the id of the record the documents are linked to.');
        }
        if (null !== $linkedId && !Id::isValid($linkedId)) {
            throw DocumentsRefused::invalid('linked_id_invalid', 'linkedId', 'linked_id is the id of a record.');
        }
        $limit ??= 50;
        if ($limit < 1 || $limit > DocumentFilter::MAX_LIMIT) {
            throw DocumentsRefused::invalid('limit_invalid', 'limit', \sprintf('limit is 1 to %d.', DocumentFilter::MAX_LIMIT));
        }
        $offset ??= 0;
        if ($offset < 0) {
            throw DocumentsRefused::invalid('offset_invalid', 'offset', 'offset is 0 or more.');
        }

        return $this->store->documents(new DocumentFilter($typeId, $state, $linkedKind, $linkedId, $limit, $offset, $trashed));
    }

    /**
     * @throws DocumentsRefused
     */
    public function document(string $id): Document
    {
        $this->access->denyUnlessStaff();

        return (Id::isValid($id) ? $this->store->document($id) : null) ?? throw DocumentsRefused::notFound('There is no such document.');
    }

    /**
     * The attachment id of a stored file of a document: pdf or signed. A certificate
     * comes only from a signature provider, so a manually signed document has none.
     *
     * @throws DocumentsRefused not found when the document has no such file
     */
    public function fileOf(string $documentId, string $file): string
    {
        $document = $this->document($documentId);
        $attachmentId = match ($file) {
            'pdf' => $document->pdfAttachmentId(),
            'signed' => $document->signedAttachmentId(),
            default => null,
        };

        return $attachmentId ?? throw DocumentsRefused::notFound(\sprintf('The document has no %s file.', $file));
    }
}
