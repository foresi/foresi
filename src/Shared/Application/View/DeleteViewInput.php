<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A saved view deleted. A view a module ships is never deleted; an owner hides it.
 */
#[Operation(
    name: 'views.delete',
    method: 'DELETE',
    path: '/api/v1/views/{viewId}',
    tool: 'foresi_delete_view',
    scope: 'views:write',
    title: 'Delete a saved view',
    description: 'Deletes a saved view, for everyone who sees it; it cannot be taken back. A shared view is deleted by its creator and the owners. A view a module ships is never deleted: an owner hides it with foresi_update_view.',
    destructive: true,
)]
final readonly class DeleteViewInput
{
    public function __construct(
        #[Field('The id of the view, as foresi_list_views gives it.')]
        public string $viewId,
    ) {
    }
}
