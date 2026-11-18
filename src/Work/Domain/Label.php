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
 * every task with it. Names are unique: the index refuses an exact
 * duplicate, and the application refuses one differing only in case, which
 * SQLite and PostgreSQL would compare differently.
 */
#[ORM\Entity]
#[ORM\Table(name: 'label')]
#[ORM\UniqueConstraint(name: 'uniq_label_name', columns: ['name'])]
class Label implements Audited
{
    public const int MAX_NAME_LENGTH = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH)]
    private string $name;

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
