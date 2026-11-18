<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Api\Field;

/**
 * A saved view deleted. A view a module ships is never deleted; an owner hides it.
 */
final readonly class DeleteViewInput
{
    public function __construct(
        #[Field('The id of the view, as foresi_list_views gives it.')]
        public string $viewId,
    ) {
    }
}
