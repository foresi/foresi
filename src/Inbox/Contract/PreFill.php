<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

/**
 * The starting values of an inbox action's fields and the choices of a field
 * picked from a list, such as the client's active projects.
 */
final readonly class PreFill
{
    /**
     * @param array<string, mixed>                 $values  field values by the field's name in the input (camel case)
     * @param array<string, array<string, string>> $choices per field name, value => label, for a select in the dialog
     */
    public function __construct(
        public array $values = [],
        public array $choices = [],
    ) {
    }
}
