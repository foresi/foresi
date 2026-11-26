<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Field;

/**
 * The kind of a custom field (ADR 0045): what it holds and the Doctrine column type
 * its property maps to. A select keeps the key of one of its options, a multi-select
 * a JSON list of them; a contact or a user reference keeps the record's id.
 */
enum FieldKind: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case Url = 'url';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Contact = 'contact';
    case User = 'user';

    /**
     * The Doctrine column types a property of this kind may map to.
     *
     * @return list<string>
     */
    public function columnTypes(): array
    {
        return match ($this) {
            self::Text, self::Url, self::Select => ['string'],
            self::LongText => ['text'],
            self::Number => ['integer', 'smallint', 'bigint', 'decimal', 'float'],
            self::Date => ['date_immutable'],
            self::DateTime => ['datetime_immutable'],
            self::Boolean => ['boolean'],
            self::MultiSelect => ['json'],
            self::Contact, self::User => ['guid'],
        };
    }

    /**
     * Whether the field takes its values from its options.
     */
    public function selects(): bool
    {
        return self::Select === $this || self::MultiSelect === $this;
    }

    /**
     * Whether a list may sort by it: a long text and a set of options have no order.
     */
    public function sortable(): bool
    {
        return self::LongText !== $this && self::MultiSelect !== $this;
    }

    /**
     * Whether the record's text search may read it.
     */
    public function searchable(): bool
    {
        return self::Text === $this || self::LongText === $this || self::Url === $this;
    }
}
