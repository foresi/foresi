<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract;

/**
 * The version of the module API this Foresi provides (ADR 0044): the Contract
 * namespaces, the classes marked @api and the names the contract lists. It has
 * a version of its own, apart from the release: an addition raises its minor,
 * a removal or an incompatible change its major.
 *
 * foresi/shared provides it to Composer as the virtual package
 * foresi/module-api, and every module requires a range of it, such as
 * "foresi/module-api": "^1.0". Composer refuses an incompatible set, and the
 * container is not built with a module whose range this version does not
 * satisfy, or that requires none.
 */
final class ModuleApi
{
    /** The same version as "provide" of foresi/shared's composer.json. */
    public const string VERSION = '1.4.0';

    /** The virtual package of the module API. */
    public const string PACKAGE = 'foresi/module-api';

    private function __construct()
    {
    }
}
