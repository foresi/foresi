<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * A template of one type of document. Its text lives in its versions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'documents_template')]
#[ORM\Index(name: 'idx_documents_template_type', columns: ['type_id'])]
class DocumentTemplate implements Audited
{
    public const int MAX_NAME = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'type_id', type: 'guid')]
    private string $typeId;

    #[ORM\Column(type: 'string', length: self::MAX_NAME)]
    private string $name;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(DocumentType $type, string $name, #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt)
    {
        $this->id = Id::generate();
        $this->typeId = $type->id();
        $this->rename($name);
    }

    private function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException(\sprintf('A template name has 1 to %d characters.', self::MAX_NAME));
        }
        $this->name = $name;
    }

    public function archive(\DateTimeImmutable $now): void
    {
        $this->archivedAt ??= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function typeId(): string
    {
        return $this->typeId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
