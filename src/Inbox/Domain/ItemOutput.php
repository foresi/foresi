<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Id;

/**
 * A record that came of an inbox item, such as the deal it was linked to, by
 * the record's kind and id. A record of its own, not a collection of the item,
 * since changes to collections of audited entities are not audited. Audited
 * under its item, so the item's history says who linked which record and when.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_item_output')]
class ItemOutput implements Audited, AuditRooted
{
    public const int MAX_KIND = 40;

    #[ORM\Column(name: 'added_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $addedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: InboxItem::class)]
        #[ORM\JoinColumn(name: 'item_id', nullable: false, onDelete: 'CASCADE')]
        private InboxItem $item,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: self::MAX_KIND)]
        private string $kind,
        #[ORM\Id]
        #[ORM\Column(name: 'record_id', type: 'guid')]
        private string $recordId,
        \DateTimeImmutable $addedAt,
        #[ORM\Column(name: 'added_by', type: 'guid')]
        private string $addedBy,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $kind)) {
            throw new \InvalidArgumentException('An output kind is a key of up to 40 lower-case letters, digits and underscores.');
        }
        if (!Id::isValid($recordId)) {
            throw new \InvalidArgumentException('An output names its record by its UUIDv7 id.');
        }
        $this->addedAt = $addedAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public function auditRoot(): object
    {
        return $this->item;
    }

    public function itemId(): string
    {
        return $this->item->id();
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function recordId(): string
    {
        return $this->recordId;
    }

    public function addedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function addedBy(): string
    {
        return $this->addedBy;
    }
}
