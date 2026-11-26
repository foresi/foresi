<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

/**
 * Marks the field of an operation's input that carries the custom fields of a
 * record (ADR 0045), an object by field key (Field::ONE_OBJECT). The MCP tool and
 * the OpenAPI document describe it from the record's field definitions.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class CustomFieldsOf
{
    /**
     * @param string $record the record kind, such as ContactExtension::RECORD
     */
    public function __construct(public string $record)
    {
    }
}
