<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\Field\FieldDefinition;

/**
 * Port: the custom field a property of a record extension holds, such as what the
 * history of a record names when an audit entry of the extension lists a property.
 */
interface FieldProperties
{
    /**
     * @param string $entityClass the class of a record extension
     */
    public function definitionOf(string $entityClass, string $property): ?FieldDefinition;
}
