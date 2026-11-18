<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Api\Field;

/**
 * The views a module ships on a list back to what it declares: renamed, edited
 * and hidden ones alike (spec D6). The views people made stay.
 */
final readonly class RestoreDefaultViewsInput
{
    public function __construct(
        #[Field('The list, such as "invoicing.invoices".', maxLength: 80)]
        public string $listKey,
    ) {
    }
}
