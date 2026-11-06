<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * One record of a firm's iDoklad account exactly as the API returned it, kept
 * so the import maps it later without asking iDoklad again. A later pull
 * replaces the payload; a changed payload asks for its PDF again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idoklad_record')]
#[ORM\Index(name: 'idx_idoklad_record_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_idoklad_record', columns: ['legal_entity_id', 'resource', 'external_id'])]
class IdokladRecord implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    /** The record as JSON, byte for byte as it was encoded after decoding the answer. */
    #[ORM\Column(type: 'text')]
    private string $payload;

    /** When iDoklad last changed the record, in its own time; null when the list does not say. */
    #[ORM\Column(name: 'changed_at', type: 'string', length: 32, nullable: true)]
    private ?string $changedAt;

    #[ORM\Column(name: 'pdf_key', type: 'string', length: 255, nullable: true)]
    private ?string $pdfKey = null;

    #[ORM\Column(name: 'pdf_sha256', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $pdfSha256 = null;

    /**
     * @param array<mixed> $payload
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(type: 'string', length: 32, enumType: IdokladResource::class)]
        private readonly IdokladResource $resource,
        #[ORM\Column(name: 'external_id', type: 'integer')]
        private readonly int $externalId,
        array $payload,
        #[ORM\Column(name: 'pulled_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $pulledAt,
    ) {
        $this->id = Id::generate();
        $this->payload = '';
        $this->changedAt = null;
        $this->replace($payload, $this->pulledAt);
    }

    /**
     * @param array<mixed> $payload
     */
    public function replace(array $payload, \DateTimeImmutable $pulledAt): void
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        if ($json !== $this->payload) {
            $this->pdfKey = null;
            $this->pdfSha256 = null;
        }
        $this->payload = $json;
        $this->pulledAt = $pulledAt;
        $this->changedAt = $this->changeOf($payload);
    }

    public function attachPdf(string $key, string $sha256): void
    {
        $this->pdfKey = $key;
        $this->pdfSha256 = $sha256;
    }

    /**
     * When iDoklad last changed a record: its metadata, or the moment of a log entry.
     *
     * @param array<mixed> $payload
     */
    private function changeOf(array $payload): ?string
    {
        $metadata = $payload['Metadata'] ?? null;
        $change = \is_array($metadata) ? ($metadata['DateLastChange'] ?? null) : ($payload['DateOfAction'] ?? null);

        return \is_string($change) && 1 === preg_match(IdokladPull::MOMENT, $change) ? $change : null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function resource(): IdokladResource
    {
        return $this->resource;
    }

    public function externalId(): int
    {
        return $this->externalId;
    }

    /**
     * @return array<mixed>
     */
    public function payload(): array
    {
        $decoded = json_decode($this->payload, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }

    public function pulledAt(): \DateTimeImmutable
    {
        return $this->pulledAt;
    }

    public function changedAt(): ?string
    {
        return $this->changedAt;
    }

    public function pdfKey(): ?string
    {
        return $this->pdfKey;
    }

    public function pdfSha256(): ?string
    {
        return $this->pdfSha256;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
