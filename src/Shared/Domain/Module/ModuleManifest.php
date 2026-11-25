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
 * a guest may hold and how far (ADR 0041), the Symfony bundle the kernel
 * registers for it, and the range of the module API it is written for
 * (ADR 0044).
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
     * @param ?string             $moduleApi   the range of foresi/module-api in "require", such as "^1.0"; null when it requires none
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
        public ?string $moduleApi = null,
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

    /**
     * The start of the name of every MCP tool of the module: "foresi_" for a
     * package foresi/<id>, the id in snake case for any other, "acme_fleet_".
     */
    public function toolPrefix(): string
    {
        return str_starts_with($this->package, ModuleNamespace::OWN_VENDOR.'/')
            ? ModuleNamespace::OWN_VENDOR.'_'
            : str_replace('-', '_', $this->id->value).'_';
    }

    /**
     * Whether a class, such as the input of an operation, belongs to the module.
     */
    public function owns(string $class): bool
    {
        return str_starts_with($class, $this->namespace);
    }
}
