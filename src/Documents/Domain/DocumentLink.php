<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A record a document belongs to, by its kind and id. The record may go away;
 * the link stays and the document shows it as unavailable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'documents_document_link')]
#[ORM\Index(name: 'idx_documents_document_link_target', columns: ['kind', 'target_id'])]
class DocumentLink
{
    public const int MAX_KIND = 40;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Document::class)]
        #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
        private Document $document,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: self::MAX_KIND)]
        private string $kind,
        #[ORM\Id]
        #[ORM\Column(name: 'target_id', type: 'guid')]
        private string $targetId,
    ) {
    }

    public function documentId(): string
    {
        return $this->document->id();
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }
}
