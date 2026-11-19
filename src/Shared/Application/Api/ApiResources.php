<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Api;

/**
 * The resources the installed modules declare in their manifests
 * ("extra.foresi.scopes"), such as "contacts" or "invoices". A token scope
 * such as "contacts:write" is only issued for one of them (ADR 0032).
 */
interface ApiResources
{
    /**
     * @return list<string> sorted
     */
    public function declared(): array;

    /**
     * The scopes a guest's token may hold, as the modules open their resources
     * to guests (ADR 0041): "tasks:write" up to writing, "projects:read" for
     * reading only.
     *
     * @return list<string> sorted
     */
    public function guest(): array;
}
