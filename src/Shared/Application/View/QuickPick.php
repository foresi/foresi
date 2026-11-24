<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * How the dropdown of a field's quick filter gets its values (Linear's and Notion's filter chips,
 * Shopify Polaris IndexFilters: a chip named by its field opens a picker of that field's values):
 *
 * - Values: the values the field declares, an enum's or yes and no;
 * - Months: the periods relative to today, then the months the list has records in;
 * - Lookup: the records it points to, searched by name through the picker of its source;
 * - Choices: the records of a short list it points to, such as a firm's deal stages, all of them
 *   as checkboxes from the picker of its source, without a search box (an enum's look for data
 *   that each firm sets);
 * - Sheet: nothing to list (a text, a number): the chip opens the filter sheet with a row for it.
 *
 * A field takes the pick of its type unless it declares another.
 */
enum QuickPick: string
{
    case Values = 'values';
    case Months = 'months';
    case Lookup = 'lookup';
    case Choices = 'choices';
    case Sheet = 'sheet';

    public static function of(FieldType $type): self
    {
        return match ($type) {
            FieldType::Enum, FieldType::MultiEnum, FieldType::Boolean => self::Values,
            FieldType::Date => self::Months,
            FieldType::Reference => self::Lookup,
            default => self::Sheet,
        };
    }

    /**
     * Whether it lists the records of its source, through that source's picker.
     */
    public function looksUp(): bool
    {
        return self::Lookup === $this || self::Choices === $this;
    }
}
