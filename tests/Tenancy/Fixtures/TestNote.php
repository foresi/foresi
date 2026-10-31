<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Fixtures;

use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test-only tenant-owned entity, mapped in the test environment only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_note')]
#[ORM\Index(name: 'idx_test_note_tenant', columns: ['tenant_id'])]
class TestNote implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    /** @var Collection<int, TestAttachment> */
    #[ORM\OneToMany(targetEntity: TestAttachment::class, mappedBy: 'note')]
    private Collection $attachments;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        private readonly string $text,
    ) {
        $this->id = Id::generate();
        $this->attachments = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * @return list<TestAttachment>
     */
    public function attachments(): array
    {
        return array_values($this->attachments->toArray());
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
