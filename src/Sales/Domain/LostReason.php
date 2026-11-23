<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * Why a deal was lost, such as the price, picked from a list kept in order.
 * A reason seeded with the module has a key and no name until someone
 * renames it: it shows under its key's name in the person's language. An
 * archived reason stays on the deals lost for it and is not offered any more.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_lost_reason')]
#[ORM\UniqueConstraint(name: 'uniq_sales_lost_reason_key', columns: ['reason_key'])]
class LostReason implements Audited
{
    public const int MAX_NAME = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'reason_key', type: 'string', length: 40, nullable: true)]
    private ?string $key;

    #[ORM\Column(type: 'string', length: self::MAX_NAME, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: 'integer')]
    private int $position;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /**
     * @throws InvalidField
     */
    public function __construct(
        ?string $key,
        ?string $name,
        int $position,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->key = SeededName::key($key);
        $this->name = SeededName::name($name, $this->key, self::MAX_NAME);
        $this->position = $position;
    }

    /**
     * Gives it a name of its own, or none, which only one with a key may have.
     *
     * @throws InvalidField
     */
    public function rename(?string $name): void
    {
        $this->name = SeededName::name($name, $this->key, self::MAX_NAME);
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    /**
     * @return bool whether it was not archived before
     */
    public function archive(\DateTimeImmutable $at): bool
    {
        if ($this->archivedAt instanceof \DateTimeImmutable) {
            return false;
        }
        $this->archivedAt = $at;

        return true;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** The key a seeded one has, or one given when it was made; it never changes. */
    public function key(): ?string
    {
        return $this->key;
    }

    /** The name someone gave it, or null for a seeded one that keeps its translated name. */
    public function name(): ?string
    {
        return $this->name;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
