<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * The type of a field a list can be filtered by, and the operators each type takes
 * (spec "Quick filters and saved views", D4).
 */
enum FieldType: string
{
    case Enum = 'enum';
    case Reference = 'reference';
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';

    /**
     * @return list<string>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Enum => ['is', 'is_not', 'is_any_of', 'is_none_of'],
            self::Reference => ['is', 'is_not', 'is_any_of', 'is_empty', 'is_not_empty'],
            self::Text => ['contains', 'does_not_contain', 'is', 'is_empty'],
            self::Number => ['eq', 'ne', 'lt', 'le', 'gt', 'ge', 'between'],
            self::Date => ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between', 'is_empty'],
            self::Boolean => ['is'],
        };
    }
}
