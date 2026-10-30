<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the tenant decided for a kind of source: the mapping and options an import
 * proposes again when it meets the same headers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_profile')]
#[ORM\Index(name: 'idx_import_profile_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_import_profile_signature', columns: ['tenant_id', 'target_key', 'header_signature'])]
class ImportProfile implements TenantOwned
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    /** @var array<string, mixed> the output of Mapping::toArray() */
    #[ORM\Column(type: 'json')]
    private array $mapping;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        private readonly string $name,
        #[ORM\Column(name: 'target_key', type: 'string', length: 64)]
        private readonly string $targetKey,
        #[ORM\Column(name: 'preset_key', type: 'string', length: 64, nullable: true)]
        private readonly ?string $presetKey,
        Mapping $mapping,
        #[ORM\Embedded(class: ImportOptions::class, columnPrefix: 'option_')]
        private ImportOptions $options,
        #[ORM\Column(name: 'header_signature', type: 'string', length: 40, options: ['fixed' => true])]
        private string $headerSignature,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->mapping = $mapping->toArray();
        $this->updatedAt = $createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function targetKey(): string
    {
        return $this->targetKey;
    }

    public function presetKey(): ?string
    {
        return $this->presetKey;
    }

    public function mapping(): Mapping
    {
        return Mapping::fromArray($this->mapping);
    }

    public function options(): ImportOptions
    {
        return $this->options;
    }

    public function headerSignature(): string
    {
        return $this->headerSignature;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Replaces what the profile proposes with what the user just decided. */
    public function remember(Mapping $mapping, ImportOptions $options, string $headerSignature, \DateTimeImmutable $at): void
    {
        $this->mapping = $mapping->toArray();
        $this->options = $options;
        $this->headerSignature = $headerSignature;
        $this->updatedAt = $at;
    }
}
