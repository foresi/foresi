<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\Module;

/**
 * What a module declares about itself: hard dependencies it cannot run
 * without, optional ones it uses only when they are installed, and the
 * resources of the API its tokens can be scoped to (ADR 0032), which of them
 * a guest may hold and how far (ADR 0041), and the Symfony bundle the kernel
 * registers for it.
 */
final readonly class ModuleManifest
{
    /** The resource of a scope, as ApiScope reads it. */
    public const string RESOURCE = '/^[a-z][a-z0-9_]{0,39}$/';

    /**
     * @param list<ModuleId>      $requires
     * @param list<ModuleId>      $optional
     * @param list<string>        $scopes      resources such as "contacts", for the scopes "contacts:read" and "contacts:write"
     * @param ?string             $bundle      the class of the module's bundle, such as "Foresi\\Work\\WorkBundle"
     * @param array<string, bool> $guestScopes resources of $scopes a guest's token may hold: true up to writing, false for reading only
     */
    public function __construct(
        public ModuleId $id,
        public ModuleKind $kind,
        public string $package,
        public string $namespace,
        public array $requires,
        public array $optional,
        public array $scopes = [],
        public ?string $bundle = null,
        public array $guestScopes = [],
    ) {
        if (null !== $bundle && 1 !== preg_match('/^'.preg_quote($namespace, '/').'[A-Z][A-Za-z0-9]*Bundle$/', $bundle)) {
            throw new InvalidManifest(\sprintf('Module %s names the bundle "%s"; a bundle is a class at the root of %s ending in "Bundle".', $id->value, $bundle, $namespace));
        }
        foreach ($scopes as $scope) {
            if (1 !== preg_match(self::RESOURCE, $scope)) {
                throw new InvalidManifest(\sprintf('Module %s declares the scope "%s"; a scope is a lowercase word such as "contacts".', $id->value, $scope));
            }
        }
        foreach (array_keys($guestScopes) as $resource) {
            if (!\in_array($resource, $scopes, true)) {
                throw new InvalidManifest(\sprintf('Module %s opens the scope "%s" to guests but does not declare it.', $id->value, $resource));
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
