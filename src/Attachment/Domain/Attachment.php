<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Domain;

use App\Audit\Domain\Audited;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file kept for a record of any module, named by the kind of the record and
 * its id. The bytes live in the private file store under the storage key and
 * are checked against the SHA-256 on every read. Deleting only marks it: a
 * later purge removes the file.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attachment')]
#[ORM\Index(name: 'idx_attachment_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_attachment_owner', columns: ['tenant_id', 'owner_kind', 'owner_id'])]
class Attachment implements TenantOwned, Audited
{
    public const int MAX_OWNER_KIND_LENGTH = 40;
    public const int MAX_MEDIA_TYPE_LENGTH = 127;
    private const string OWNER_KIND = '/^[a-z][a-z0-9_]*$/D';
    private const string MEDIA_TYPE = '~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~D';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private readonly string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'file_name', type: 'string', length: FileName::MAX_LENGTH)]
    private readonly string $fileName;

    #[ORM\Column(name: 'media_type', type: 'string', length: self::MAX_MEDIA_TYPE_LENGTH)]
    private readonly string $mediaType;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * @throws \DomainException when a value cannot describe a stored file
     */
    public function __construct(
        string $id,
        #[ORM\Column(name: 'owner_kind', type: 'string', length: self::MAX_OWNER_KIND_LENGTH)]
        private readonly string $ownerKind,
        #[ORM\Column(name: 'owner_id', type: 'guid')]
        private readonly string $ownerId,
        string $fileName,
        string $mediaType,
        #[ORM\Column(type: 'integer')]
        private readonly int $size,
        #[ORM\Column(type: 'string', length: 64)]
        private readonly string $sha256,
        #[ORM\Column(name: 'storage_key', type: 'string', length: 255)]
        private readonly string $storageKey,
        #[ORM\Column(name: 'uploaded_by', type: 'guid')]
        private readonly string $uploadedBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if (!Id::isValid($id)) {
            throw new \DomainException('An attachment is named by a UUIDv7.');
        }
        if (\strlen($ownerKind) > self::MAX_OWNER_KIND_LENGTH || 1 !== preg_match(self::OWNER_KIND, $ownerKind)) {
            throw new \DomainException('The kind of the record is a short lowercase name.');
        }
        if (!$this->isGuid($ownerId) || !$this->isGuid($uploadedBy)) {
            throw new \DomainException('The record and the person are named by their ids.');
        }
        $mediaType = strtolower($mediaType);
        if (\strlen($mediaType) > self::MAX_MEDIA_TYPE_LENGTH || 1 !== preg_match(self::MEDIA_TYPE, $mediaType)) {
            throw new \DomainException('The media type is a type and a subtype.');
        }
        if ($size <= 0) {
            throw new \DomainException('An attachment is not empty.');
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $sha256)) {
            throw new \DomainException('The SHA-256 is 64 lowercase hex digits.');
        }
        if ('' === $storageKey) {
            throw new \DomainException('An attachment is stored under a key.');
        }
        $this->id = $id;
        $this->fileName = FileName::clean($fileName);
        $this->mediaType = $mediaType;
    }

    public function delete(\DateTimeImmutable $now): void
    {
        $this->deletedAt ??= $now;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }

    public function belongsTo(string $ownerKind, string $ownerId): bool
    {
        return $this->ownerKind === $ownerKind && $this->ownerId === $ownerId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ownerKind(): string
    {
        return $this->ownerKind;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function mediaType(): string
    {
        return $this->mediaType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function uploadedBy(): string
    {
        return $this->uploadedBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function isGuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value);
    }
}
