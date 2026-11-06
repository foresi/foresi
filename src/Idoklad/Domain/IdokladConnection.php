<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Audit\Domain\AuditRooted;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * The API credentials of a firm's iDoklad account, one per firm: a client id
 * and a client secret of the client credentials flow. The secret is held only
 * sealed, and never written to the audit log.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idoklad_connection')]
#[ORM\Index(name: 'idx_idoklad_connection_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_idoklad_connection_firm', columns: ['legal_entity_id'])]
class IdokladConnection implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'client_id', type: 'string', length: 255)]
    private string $clientId;

    #[AuditExcluded]
    #[ORM\Column(name: 'secret_sealed', type: 'text')]
    private string $sealedSecret;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        string $clientId,
        string $sealedSecret,
    ) {
        $this->id = Id::generate();
        $this->change($clientId, $sealedSecret);
    }

    public function change(string $clientId, string $sealedSecret): void
    {
        $clientId = trim($clientId);
        if ('' === $clientId || mb_strlen($clientId) > 255 || 1 === preg_match('/[\x00-\x1F\x7F]/', $clientId)) {
            throw new InvalidIdokladConnection('The iDoklad client id is one line of up to 255 characters.');
        }
        if ('' === $sealedSecret) {
            throw new InvalidIdokladConnection('The iDoklad connection needs a client secret.');
        }
        $this->clientId = $clientId;
        $this->sealedSecret = $sealedSecret;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->firm;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function sealedSecret(): string
    {
        return $this->sealedSecret;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
