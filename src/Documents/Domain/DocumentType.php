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
 * A kind of document, such as an NDA or a service contract: whether it needs
 * signing and at which level. Its code is unique and never changes, so other
 * modules and events can name it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'documents_type')]
#[ORM\UniqueConstraint(name: 'uniq_documents_type_code', columns: ['code'])]
class DocumentType implements Audited
{
    public const int MAX_NAME = 120;
    public const int MAX_CODE = 40;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_NAME)]
    private string $name;

    #[ORM\Column(type: 'string', length: self::MAX_CODE)]
    private string $code;

    #[ORM\Column(name: 'needs_signing', type: 'boolean')]
    private bool $needsSigning;

    #[ORM\Column(name: 'signature_level', type: 'string', length: 16, enumType: SignatureLevel::class)]
    private SignatureLevel $signatureLevel;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(string $name, string $code, bool $needsSigning, SignatureLevel $signatureLevel, #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt)
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code)) {
            throw new \InvalidArgumentException('A type code is a lowercase identifier of up to 40 characters, such as "nda".');
        }
        $this->id = Id::generate();
        $this->code = $code;
        $this->change($name, $needsSigning, $signatureLevel);
    }

    public function change(string $name, bool $needsSigning, SignatureLevel $signatureLevel): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException(\sprintf('A type name has 1 to %d characters.', self::MAX_NAME));
        }
        $this->name = $name;
        $this->needsSigning = $needsSigning;
        $this->signatureLevel = $signatureLevel;
    }

    public function archive(\DateTimeImmutable $now): void
    {
        $this->archivedAt ??= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function needsSigning(): bool
    {
        return $this->needsSigning;
    }

    public function signatureLevel(): SignatureLevel
    {
        return $this->signatureLevel;
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
