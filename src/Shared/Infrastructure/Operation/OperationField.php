<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\Operation\Field;

/**
 * One field of an operation's input, read from a constructor parameter of the
 * input class: its name in the class and over the wire, its type, and whether
 * it may be left out, be null, or is part of the path.
 */
final readonly class OperationField
{
    public const array TYPES = ['string', 'int', 'bool', 'array'];

    public function __construct(
        public string $name,
        public string $wire,
        public string $type,
        public bool $nullable,
        public bool $required,
        public bool $inPath,
        public ?Field $describe,
    ) {
    }

    /**
     * The name of a field as the client sends it: contactId is contact_id.
     */
    public static function wireName(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
