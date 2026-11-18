<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * The views a module ships on a list back to what it declares: renamed, edited
 * and hidden ones alike (spec D6). The views people made stay.
 */
#[Operation(
    name: 'views.restore_defaults',
    method: 'POST',
    path: '/api/v1/views/restore-defaults',
    tool: 'foresi_restore_default_views',
    scope: 'views:write',
    title: 'Restore the default views of a list',
    description: 'Brings the views a module ships on a list back to what it declares: their names, conditions, order and placement, hidden ones shown again. The views people made stay as they are. Only owners restore defaults.',
)]
final readonly class RestoreDefaultViewsInput
{
    public function __construct(
        #[Field('The list, such as "invoicing.invoices".', maxLength: 80)]
        public string $listKey,
    ) {
    }
}
