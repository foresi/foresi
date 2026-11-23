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
 * Test-only audited entity whose items are a collection of the inverse side:
 * the items hold the reference and are audited on their own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_audited_list')]
class AuditedList implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** @var Collection<int, AuditedListItem> */
    #[ORM\OneToMany(targetEntity: AuditedListItem::class, mappedBy: 'list', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->id = Id::generate();
        $this->items = new ArrayCollection();
    }

    public function add(string $label): AuditedListItem
    {
        $item = new AuditedListItem($this, $label);
        $this->items->add($item);

        return $item;
    }

    public function remove(AuditedListItem $item): void
    {
        $this->items->removeElement($item);
    }
}
