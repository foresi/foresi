<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Audit\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Id;

/**
 * Test-only audited item of an AuditedList, recorded under it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_audited_list_item')]
class AuditedListItem implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: AuditedList::class, inversedBy: 'items')]
        #[ORM\JoinColumn(name: 'list_id', nullable: false)]
        private readonly AuditedList $list,
        #[ORM\Column(type: 'string', length: 64)]
        private string $label,
    ) {
        $this->id = Id::generate();
    }

    public function auditRoot(): object
    {
        return $this->list;
    }

    public function relabel(string $label): void
    {
        $this->label = $label;
    }
}
