<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\View;

/**
 * The type of an attribute of a data source, such as the due date of an invoice (spec DS4). The
 * screens format a value by its type, and the filter fields of saved views derive their type
 * from it, so it lives where both can read it.
 */
enum AttributeType: string
{
    case Text = 'text';
    case Number = 'number';
    case Money = 'money';
    case Date = 'date';
    case DateTime = 'datetime';
    case Enum = 'enum';
    case Boolean = 'boolean';
    /** A linked record, shown by its name. */
    case Reference = 'reference';
    case Email = 'email';
    case Phone = 'phone';
    case Url = 'url';
    /** An ISO 3166-1 alpha-2 code, shown by the country's name. */
    case Country = 'country';

    /**
     * Amounts and counts line up on the right, everything else on the left.
     */
    public function numeric(): bool
    {
        return self::Money === $this || self::Number === $this;
    }
}
