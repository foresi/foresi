<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Operation;

use App\Shared\Application\RefusalKind;
use App\Shared\Application\Refused;

/**
 * A field of an operation's input that is missing, of the wrong type, not one
 * of its values, or not a field at all: REST 400 invalid_parameter, MCP
 * invalid_argument, both naming the field as the client sends it.
 */
final class InvalidInput extends \InvalidArgumentException implements Refused
{
    public function __construct(public readonly string $wire, string $problem)
    {
        parent::__construct(\sprintf('The field "%s" %s', $wire, $problem));
    }

    public function reason(): string
    {
        return 'invalid_input';
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::Invalid;
    }

    public function field(): string
    {
        return lcfirst(str_replace('_', '', ucwords($this->wire, '_')));
    }

    public function details(): array
    {
        return [];
    }
}
