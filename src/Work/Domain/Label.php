<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * A label of the organisation, shared by all its projects, so one filter finds
 * every task with it. Names are unique among the labels out of the trash: the
 * index refuses an exact duplicate, and the application refuses one differing
 * only in case, which SQLite and PostgreSQL would compare differently.
 *
 * Deleting a label puts it in the trash with who did it: it leaves no task,
 * but shows on none, and its name is free for another label. Restored within
 * 30 days, it is back on the same tasks; then it is removed for good.
 */
#[ORM\Entity]
#[ORM\Table(name: 'label')]
#[ORM\UniqueConstraint(name: 'uniq_label_live_name', columns: ['live_name'])]
#[ORM\Index(name: 'idx_label_deleted', columns: ['deleted_at'])]
class Label implements Audited
{
    public const int MAX_NAME_LENGTH = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH)]
    private string $name;

    // The name while the label is out of the trash, null in it. Unique indexes ignore nulls on
    // every database, which a partial index cannot promise: Doctrine cannot read one back from
    // SQLite, so schema validation would fail there (as LegalEntity::$activeRegistrationKey).
    #[ORM\Column(name: 'live_name', type: 'string', length: self::MAX_NAME_LENGTH, nullable: true)]
    private ?string $liveName = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** The user who put the label in the trash. */
    #[ORM\Column(name: 'deleted_by', type: 'guid', nullable: true)]
    private ?string $deletedBy = null;

    public function __construct(
        string $name,
        #[ORM\Column(type: 'string', length: 16, enumType: LabelColour::class)]
        private LabelColour $colour,
    ) {
        $this->id = Id::generate();
        $this->rename($name);
    }

    public function rename(string $name): void
    {
        $this->name = Name::of($name, self::MAX_NAME_LENGTH, 'A label');
        if (!$this->isDeleted()) {
            $this->liveName = $this->name;
        }
    }

    /**
     * Trashing it again keeps the first date and who.
     */
    public function trash(string $userId, \DateTimeImmutable $now): void
    {
        if ($this->isDeleted()) {
            return;
        }
        $this->deletedAt = $now;
        $this->deletedBy = $userId;
        $this->liveName = null;
    }

    /**
     * Takes it out of the trash under its name; restoring one that stands changes nothing.
     */
    public function restore(): void
    {
        $this->deletedAt = null;
        $this->deletedBy = null;
        $this->liveName = $this->name;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function deletedBy(): ?string
    {
        return $this->deletedBy;
    }

    public function recolour(LabelColour $colour): void
    {
        $this->colour = $colour;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function colour(): LabelColour
    {
        return $this->colour;
    }
}
