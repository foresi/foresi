<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Support;

use Foresi\DocumentTemplates\Application\DocumentFilter;
use Foresi\DocumentTemplates\Application\DocumentsStore;
use Foresi\DocumentTemplates\Domain\Document;
use Foresi\DocumentTemplates\Domain\DocumentLink;
use Foresi\DocumentTemplates\Domain\DocumentTemplate;
use Foresi\DocumentTemplates\Domain\DocumentType;
use Foresi\DocumentTemplates\Domain\Signer;
use Foresi\DocumentTemplates\Domain\TemplateVersion;

/**
 * The store with types or template versions gone, as data broken outside the
 * application would be, so the writes show they never take that as valid.
 */
final readonly class ForgetfulStore implements DocumentsStore
{
    public function __construct(private DocumentsStore $store, private bool $types, private bool $versions)
    {
    }

    public function type(string $id): ?DocumentType
    {
        return $this->types ? null : $this->store->type($id);
    }

    public function typeByCode(string $code): ?DocumentType
    {
        return $this->store->typeByCode($code);
    }

    public function types(bool $withArchived): array
    {
        return $this->store->types($withArchived);
    }

    public function template(string $id): ?DocumentTemplate
    {
        return $this->store->template($id);
    }

    public function templates(?string $typeId, bool $withArchived): array
    {
        return $this->store->templates($typeId, $withArchived);
    }

    public function version(string $id): ?TemplateVersion
    {
        return $this->store->version($id);
    }

    public function latestVersion(string $templateId): ?TemplateVersion
    {
        return $this->versions ? null : $this->store->latestVersion($templateId);
    }

    public function versions(string $templateId): array
    {
        return $this->store->versions($templateId);
    }

    public function document(string $id): ?Document
    {
        return $this->store->document($id);
    }

    public function documents(DocumentFilter $filter): array
    {
        return $this->store->documents($filter);
    }

    public function links(Document $document): array
    {
        return $this->store->links($document);
    }

    public function signers(Document $document): array
    {
        return $this->store->signers($document);
    }

    public function signer(Document $document, string $signerId): ?Signer
    {
        return $this->store->signer($document, $signerId);
    }

    public function add(DocumentType|DocumentTemplate|TemplateVersion|Document|DocumentLink|Signer $record): void
    {
        $this->store->add($record);
    }

    public function remove(DocumentLink|Signer $record): void
    {
        $this->store->remove($record);
    }
}
