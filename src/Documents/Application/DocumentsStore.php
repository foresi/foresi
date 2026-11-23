<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentLink;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\Signer;
use Foresi\Documents\Domain\TemplateVersion;

interface DocumentsStore
{
    public function type(string $id): ?DocumentType;

    public function typeByCode(string $code): ?DocumentType;

    /**
     * @return list<DocumentType> in name order
     */
    public function types(bool $withArchived): array;

    public function template(string $id): ?DocumentTemplate;

    /**
     * @return list<DocumentTemplate> in name order
     */
    public function templates(?string $typeId, bool $withArchived): array;

    public function version(string $id): ?TemplateVersion;

    public function latestVersion(string $templateId): ?TemplateVersion;

    /**
     * @return list<TemplateVersion> newest first
     */
    public function versions(string $templateId): array;

    public function document(string $id): ?Document;

    /**
     * The documents out of the trash the filter lets through, or with its
     * trashed those in the trash, newest first.
     *
     * @return list<Document>
     */
    public function documents(DocumentFilter $filter): array;

    /**
     * @return list<DocumentLink>
     */
    public function links(Document $document): array;

    /**
     * In signing order, then in the order they were added.
     *
     * @return list<Signer>
     */
    public function signers(Document $document): array;

    public function signer(Document $document, string $signerId): ?Signer;

    public function add(DocumentType|DocumentTemplate|TemplateVersion|Document|DocumentLink|Signer $record): void;

    public function remove(DocumentLink|Signer $record): void;
}
