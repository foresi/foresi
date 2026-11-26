<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\Fields;

/**
 * The custom fields of a record kind the current actor sees and writes (ADR 0045):
 * every field for the staff and the application's own work, those open to guests for
 * a guest. Through an API token, over REST or MCP, a field with api: false is not
 * seen either.
 */
final readonly class VisibleFields
{
    public function __construct(private Fields $fields, private ActorRole $actor, private ?ScopeGuard $scopes = null)
    {
    }

    /**
     * @return list<FieldDefinition>
     */
    public function definitions(string $record): array
    {
        return array_values(array_filter($this->fields->definitions($record), $this->visible(...)));
    }

    public function definition(string $record, string $key): ?FieldDefinition
    {
        $definition = $this->fields->definition($record, $key);

        return $definition instanceof FieldDefinition && $this->visible($definition) ? $definition : null;
    }

    private function visible(FieldDefinition $definition): bool
    {
        return ($definition->guests || !$this->guest()) && ($definition->api || !$this->api());
    }

    /**
     * Whether a guest is signed in; nobody signed in, such as a console command, is the application's own work.
     */
    private function guest(): bool
    {
        return null !== $this->actor->userId() && !$this->actor->isStaff();
    }

    /**
     * Whether the request or process signed in with an API token.
     */
    private function api(): bool
    {
        return true === $this->scopes?->allows(ScopeGuard::ANY);
    }
}
