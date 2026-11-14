<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Module;

/**
 * What a module declares about itself: hard dependencies it cannot run
 * without, optional ones it uses only when they are installed, and the
 * resources of the API its tokens can be scoped to (ADR 0032).
 */
final readonly class ModuleManifest
{
    /** The resource of a scope, as ApiScope reads it. */
    public const string RESOURCE = '/^[a-z][a-z0-9_]{0,39}$/';

    /**
     * @param list<ModuleId> $requires
     * @param list<ModuleId> $optional
     * @param list<string>   $scopes   resources such as "contacts", for the scopes "contacts:read" and "contacts:write"
     */
    public function __construct(
        public ModuleId $id,
        public ModuleKind $kind,
        public string $package,
        public string $namespace,
        public array $requires,
        public array $optional,
        public array $scopes = [],
    ) {
        foreach ($scopes as $scope) {
            if (1 !== preg_match(self::RESOURCE, $scope)) {
                throw new InvalidManifest(\sprintf('Module %s declares the scope "%s"; a scope is a lowercase word such as "contacts".', $id->value, $scope));
            }
        }
        foreach ([...$requires, ...$optional] as $dependency) {
            if ($dependency->equals($id)) {
                throw new InvalidManifest(\sprintf('Module %s depends on itself.', $id->value));
            }
        }
        foreach ($optional as $dependency) {
            foreach ($requires as $required) {
                if ($dependency->equals($required)) {
                    throw new InvalidManifest(\sprintf('Module %s lists %s as both required and optional.', $id->value, $dependency->value));
                }
            }
        }
    }
}
