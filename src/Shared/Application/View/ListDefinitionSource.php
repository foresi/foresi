<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * A module's lists that saved views apply to: each service tagged so is read by
 * ListDefinitions (the Shared module's services.php tags it), and app:views:sync stores the
 * views they ship.
 */
interface ListDefinitionSource
{
    public const string TAG = 'foresi.list_definitions';

    /**
     * @return iterable<ListDefinition>
     */
    public function lists(): iterable;
}
