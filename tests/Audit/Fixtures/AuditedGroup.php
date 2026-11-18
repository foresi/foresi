<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Audit\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * Test-only audited entity that owns a to-many association.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_audited_group')]
class AuditedGroup implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** @var Collection<int, AuditedSecret> */
    #[ORM\ManyToMany(targetEntity: AuditedSecret::class)]
    #[ORM\JoinTable(name: 'test_audited_group_secret')]
    private Collection $members;

    public function __construct()
    {
        $this->id = Id::generate();
        $this->members = new ArrayCollection();
    }

    public function add(AuditedSecret $secret): void
    {
        $this->members->add($secret);
    }
}
