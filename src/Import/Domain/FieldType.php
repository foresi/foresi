<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** How the text of a source cell is read for a target field. */
enum FieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case EmailList = 'email_list';
    case Phone = 'phone';
    case Date = 'date';
    case Decimal = 'decimal';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Choice = 'choice';
    case Country = 'country';
    case Identifier = 'identifier';
    case Uuid = 'uuid';
}
