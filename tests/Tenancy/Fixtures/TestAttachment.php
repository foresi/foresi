<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Fixtures;

use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test-only tenant-owned entity related to TestNote.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_attachment')]
#[ORM\Index(name: 'idx_test_attachment_tenant', columns: ['tenant_id'])]
class TestAttachment implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TestNote::class, inversedBy: 'attachments')]
        #[ORM\JoinColumn(nullable: false)]
        private TestNote $note,
        #[ORM\Column(type: 'string', length: 255)]
        private readonly string $label,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function note(): TestNote
    {
        return $this->note;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
