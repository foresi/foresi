<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Domain;

use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the append-only audit log: who changed which entity, when, and
 * how. Exposes no mutators; the audit listener rejects any update or removal
 * of a persisted entry at flush.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_entry')]
#[ORM\Index(name: 'idx_audit_entry_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_audit_entry_entity', columns: ['tenant_id', 'entity_class', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_entry_correlation', columns: ['tenant_id', 'correlation_id'])]
class AuditEntry implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'entity_class', type: 'string', length: 255)]
    private string $entityClass;

    #[ORM\Column(name: 'entity_id', type: 'string', length: 64)]
    private string $entityId;

    /** @var array<string, array<mixed>> */
    #[ORM\Column(type: 'json')]
    private array $changes;

    /**
     * @param array<string, array<mixed>> $changes field => [old, new]; JSON-serialisable scalars only
     */
    public function __construct(
        string $entityClass,
        string $entityId,
        #[ORM\Column(type: 'string', length: 16, enumType: AuditAction::class)]
        private AuditAction $action,
        array $changes,
        #[ORM\Column(name: 'user_id', type: 'guid', nullable: true)]
        private ?string $userId,
        #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(name: 'correlation_id', type: 'string', length: 64, nullable: true)]
        private ?string $correlationId = null,
    ) {
        $this->id = Id::generate();
        if ('' === $entityClass || '' === $entityId) {
            throw new \DomainException('An audit entry needs the class and id of the entity it describes.');
        }
        foreach ($changes as $field => $pair) {
            if ('' === $field || 2 !== \count($pair) || !array_is_list($pair)) {
                throw new \DomainException('Each change is a field name with an [old, new] pair.');
            }
        }
        $this->entityClass = $entityClass;
        $this->entityId = $entityId;
        $this->changes = $changes;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function entityClass(): string
    {
        return $this->entityClass;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
