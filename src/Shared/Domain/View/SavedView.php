<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\View;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Id;

/**
 * A named bundle of what a list shows: its filters, its sort and its display
 * (spec "Quick filters and saved views", D1 and D2). It is private to its owner or
 * shared with the staff of the instance. A view a module ships has no owner and a
 * system key; app:views:sync keeps it as the module declares it until someone edits
 * it (updatedBy is set) or hides it.
 *
 * The filters are stored as {match, conditions}; the module's list definition
 * checks them when the view is read. The display belongs to the list columns
 * (D-display-2): kept here as it comes and read leniently by the list columns
 * (Foresi\Ui\Column\WorkingDisplay), which validate it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'saved_view')]
#[ORM\Index(name: 'idx_saved_view_list', columns: ['list_key'])]
#[ORM\UniqueConstraint(name: 'uniq_saved_view_system_key', columns: ['system_key'])]
class SavedView
{
    public const int NAME_MAX_LENGTH = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $display = null;

    #[ORM\Column(type: 'boolean')]
    private bool $hidden = false;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'updated_by', type: 'guid', nullable: true)]
    private ?string $updatedBy = null;

    /**
     * @param array<string, mixed>                    $filters
     * @param list<array{field: string, dir: string}> $sort
     */
    private function __construct(
        #[ORM\Column(name: 'list_key', type: 'string', length: 80)]
        private string $listKey,
        #[ORM\Column(type: 'string', length: self::NAME_MAX_LENGTH)]
        private string $name,
        #[ORM\Column(name: 'owner_id', type: 'guid', nullable: true)]
        private ?string $ownerId,
        #[ORM\Column(name: 'system_key', type: 'string', length: 120, nullable: true)]
        private ?string $systemKey,
        #[ORM\Column(type: 'string', length: 16, enumType: Visibility::class)]
        private Visibility $visibility,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        private array $filters,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        private array $sort,
        #[ORM\Column(name: 'show_count', type: 'boolean')]
        private bool $showCount,
        #[ORM\Column(type: 'string', length: 16, enumType: Placement::class)]
        private Placement $placement,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->checkName($name);
        $this->id = Id::generate();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * A view a module ships: shared, with no owner, as nobody has edited it.
     *
     * @param array<string, mixed>                    $filters
     * @param list<array{field: string, dir: string}> $sort
     */
    public static function shipped(string $listKey, string $systemKey, string $name, array $filters, array $sort, bool $showCount, Placement $placement, int $position, \DateTimeImmutable $now): self
    {
        return new self($listKey, $name, null, $systemKey, Visibility::Shared, $filters, $sort, $showCount, $placement, $position, $now);
    }

    /**
     * What the module now ships of a view nobody has edited or hidden; anything else
     * keeps what people made of it. It says whether anything changed.
     *
     * @param array<string, mixed>                    $filters
     * @param list<array{field: string, dir: string}> $sort
     */
    public function reship(string $name, array $filters, array $sort, bool $showCount, Placement $placement, int $position, \DateTimeImmutable $now): bool
    {
        if (!$this->isPristine()) {
            return false;
        }
        $this->checkName($name);
        // PostgreSQL's jsonb keeps the keys of an object in its own order: compared as sorted.
        if ([$this->name, self::canonical($this->filters), self::canonical($this->sort), $this->showCount, $this->placement, $this->position] === [$name, self::canonical($filters), self::canonical($sort), $showCount, $placement, $position]) {
            return false;
        }
        [$this->name, $this->filters, $this->sort, $this->showCount, $this->placement, $this->position, $this->updatedAt] = [$name, $filters, $sort, $showCount, $placement, $position, $now];

        return true;
    }

    /**
     * A view someone makes on a list: private to them, or shared with the staff.
     *
     * @param array<string, mixed>                    $filters
     * @param list<array{field: string, dir: string}> $sort
     * @param array<string, mixed>|null               $display
     */
    public static function made(string $listKey, string $ownerId, string $name, Visibility $visibility, array $filters, array $sort, ?array $display, bool $showCount, Placement $placement, int $position, \DateTimeImmutable $now): self
    {
        $view = new self($listKey, $name, $ownerId, null, $visibility, $filters, $sort, $showCount, $placement, $position, $now);
        $view->display = $display;
        $view->updatedBy = $ownerId;

        return $view;
    }

    /**
     * Someone changes what the view says; what is left null stays. A shipped view
     * is then no longer the module's: app:views:sync leaves it alone.
     *
     * @param array<string, mixed>|null                    $filters
     * @param list<array{field: string, dir: string}>|null $sort
     */
    public function edit(string $by, \DateTimeImmutable $now, ?string $name = null, ?array $filters = null, ?array $sort = null, ?bool $showCount = null, ?Placement $placement = null, ?int $position = null, ?bool $hidden = null): void
    {
        if (null !== $name) {
            $this->checkName($name);
            $this->name = $name;
        }
        $this->filters = $filters ?? $this->filters;
        $this->sort = $sort ?? $this->sort;
        $this->showCount = $showCount ?? $this->showCount;
        $this->placement = $placement ?? $this->placement;
        $this->position = $position ?? $this->position;
        $this->hidden = $hidden ?? $this->hidden;
        $this->touch($by, $now);
    }

    /**
     * @param array<string, mixed>|null $display null goes back to the list's default columns
     */
    public function redisplay(?array $display, string $by, \DateTimeImmutable $now): void
    {
        $this->display = $display;
        $this->touch($by, $now);
    }

    /**
     * Shared with the staff or private to its owner; a shipped view has no owner
     * and stays shared.
     */
    public function changeVisibility(Visibility $visibility, string $by, \DateTimeImmutable $now): void
    {
        if (null === $this->ownerId && Visibility::Private === $visibility) {
            throw new \LogicException('A view a module ships has no owner to be private to.');
        }
        $this->visibility = $visibility;
        $this->touch($by, $now);
    }

    /**
     * A shipped view back to what its module declares, shown again, and the
     * module's once more.
     *
     * @param array<string, mixed>                    $filters
     * @param list<array{field: string, dir: string}> $sort
     */
    public function restore(string $name, array $filters, array $sort, bool $showCount, Placement $placement, int $position, \DateTimeImmutable $now): void
    {
        if (null === $this->systemKey) {
            throw new \LogicException('Only a view a module ships has a default to go back to.');
        }
        $this->checkName($name);
        [$this->name, $this->filters, $this->sort, $this->showCount, $this->placement, $this->position] = [$name, $filters, $sort, $showCount, $placement, $position];
        [$this->display, $this->hidden, $this->visibility, $this->updatedBy, $this->updatedAt] = [null, false, Visibility::Shared, null, $now];
    }

    /**
     * A shipped view as the module declares it: nobody edited or hid it.
     */
    public function isPristine(): bool
    {
        return null !== $this->systemKey && null === $this->updatedBy && !$this->hidden;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function listKey(): string
    {
        return $this->listKey;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function ownerId(): ?string
    {
        return $this->ownerId;
    }

    public function systemKey(): ?string
    {
        return $this->systemKey;
    }

    public function visibility(): Visibility
    {
        return $this->visibility;
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return list<array{field: string, dir: string}>
     */
    public function sort(): array
    {
        return $this->sort;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function display(): ?array
    {
        return $this->display;
    }

    public function showCount(): bool
    {
        return $this->showCount;
    }

    public function placement(): Placement
    {
        return $this->placement;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function updatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(string $by, \DateTimeImmutable $now): void
    {
        $this->updatedBy = $by;
        $this->updatedAt = $now;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function checkName(string $name): void
    {
        if ('' === trim($name) || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A view is named in 1 to %d characters.', self::NAME_MAX_LENGTH));
        }
    }
}
