<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Support;

use Foresi\Documents\Contract\DocumentVariableSource;
use Foresi\Documents\Contract\VariableDefinition;

/**
 * The name of the linked contact under "client", applicable with a contact link.
 */
final readonly class FakeClientVariables implements DocumentVariableSource
{
    public function __construct(private FakeLinkTarget $contacts)
    {
    }

    public function prefix(): string
    {
        return 'client';
    }

    public function definitions(): array
    {
        return [new VariableDefinition('name', 'Client name', 'Example Client Ltd')];
    }

    public function values(array $links): ?array
    {
        foreach ($links as $link) {
            if ('contact' === $link->kind) {
                return ['name' => $this->contacts->name($link->id) ?? ''];
            }
        }

        return null;
    }
}
