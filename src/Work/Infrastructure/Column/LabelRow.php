<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\TrashedLabel;
use Foresi\Work\Domain\LabelColour;

/**
 * A label as a row of Settings → Labels, in use or in the trash, with the tasks that carry it.
 */
final readonly class LabelRow
{
    public function __construct(
        public string $id,
        public string $name,
        public LabelColour $colour,
        public int $tasks,
        public ?string $deletedBy = null,
        public ?\DateTimeImmutable $deletedAt = null,
    ) {
    }

    public static function of(LabelBadge $label, int $tasks): self
    {
        return new self($label->id, $label->name, $label->colour, $tasks);
    }

    public static function trashed(TrashedLabel $label, int $tasks): self
    {
        return new self($label->id, $label->name, $label->colour, $tasks, $label->deletedByName, $label->deletedAt);
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }
}
