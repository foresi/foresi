<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\Fields;

/**
 * The custom fields of a record kind the current actor sees and writes (ADR 0045):
 * every field for the staff and the application's own work, those open to guests for
 * a guest.
 */
final readonly class VisibleFields
{
    public function __construct(private Fields $fields, private ActorRole $actor)
    {
    }

    /**
     * @return list<FieldDefinition>
     */
    public function definitions(string $record): array
    {
        if (!$this->guest()) {
            return $this->fields->definitions($record);
        }

        return array_values(array_filter($this->fields->definitions($record), static fn (FieldDefinition $definition): bool => $definition->guests));
    }

    public function definition(string $record, string $key): ?FieldDefinition
    {
        $definition = $this->fields->definition($record, $key);

        return !$definition instanceof FieldDefinition || ($this->guest() && !$definition->guests) ? null : $definition;
    }

    /**
     * Whether a guest is signed in; nobody signed in, such as a console command, is the application's own work.
     */
    public function guest(): bool
    {
        return null !== $this->actor->userId() && !$this->actor->isStaff();
    }
}
