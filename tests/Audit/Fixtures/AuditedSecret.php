<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Audit\Fixtures;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test-only audited entity with a field that must never reach the log.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_audited_secret')]
#[ORM\Index(name: 'idx_test_audited_secret_tenant', columns: ['tenant_id'])]
class AuditedSecret implements TenantOwned, Audited
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $label,
        #[ORM\Column(type: 'string', length: 64)]
        #[AuditExcluded]
        private string $secret,
    ) {
    }

    public function changeSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    public function changeLabel(string $label): void
    {
        $this->label = $label;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
