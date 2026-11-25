<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\Module;

/**
 * The PSR-4 namespace of a module, such as "Foresi\CashFlow\" or
 * "Acme\Fleet\", and the names a module of it is known by (ADR 0044). The
 * namespace decides them, so a bundle can tell them from its own class:
 *
 * - Foresi's own modules, "Foresi\<Module>\" of a package foresi/<id>, have
 *   the id of the last segment (cash-flow), the alias foresi_cash_flow and the
 *   asset namespace @foresi/cash-flow.
 * - Any other vendor's, "Vendor\Module\" with two segments or more, has the id
 *   of all segments (acme-fleet), the alias acme_fleet and the asset
 *   namespace @acme-fleet. Two vendors' ids never collide, as their
 *   namespaces cannot.
 *
 * Either way the bundle is "<namespace><Studly id>Bundle", such as
 * Foresi\CashFlow\CashFlowBundle and Acme\Fleet\AcmeFleetBundle.
 */
final readonly class ModuleNamespace
{
    /** The namespace of Foresi's own modules, and the vendor of their packages. */
    public const string OWN = 'Foresi\\';
    public const string OWN_VENDOR = 'foresi';

    private const string SEGMENT = '[A-Z][A-Za-z0-9]*';

    private function __construct(
        public string $value,
    ) {
    }

    /**
     * @throws InvalidManifest when $namespace is not "Vendor\Module\", two segments or more in StudlyCase
     */
    public static function of(string $namespace): self
    {
        if (1 !== preg_match('/^'.self::SEGMENT.'\\\\('.self::SEGMENT.'\\\\)+$/', $namespace)) {
            throw new InvalidManifest(\sprintf('"%s" is not a module namespace; use "Vendor\\Module\\" in StudlyCase, such as "Acme\\Fleet\\".', $namespace));
        }

        return new self($namespace);
    }

    /**
     * The namespace of a module's bundle, which sits at its root.
     */
    public static function ofBundle(string $bundle): self
    {
        return self::of(substr($bundle, 0, (int) strrpos($bundle, '\\') + 1));
    }

    /**
     * Whether the namespace is one of Foresi's own modules, below "Foresi\".
     */
    public function own(): bool
    {
        return str_starts_with($this->value, self::OWN);
    }

    /**
     * Whether it is the namespace of a package foresi/<id>, "Foresi\<Module>\".
     */
    public function ofOwnPackage(): bool
    {
        return 1 === preg_match('/^Foresi\\\\'.self::SEGMENT.'\\\\$/', $this->value);
    }

    /**
     * The module's id: "cash-flow" for Foresi\CashFlow\, "acme-fleet" for Acme\Fleet\.
     */
    public function id(): ModuleId
    {
        $segments = explode('\\', substr($this->value, 0, -1));
        if ($this->own()) {
            $segments = \array_slice($segments, -1);
        }

        return ModuleId::of(implode('-', array_map(self::kebab(...), $segments)));
    }

    /**
     * The class of the module's bundle, such as "Acme\Fleet\AcmeFleetBundle".
     */
    public function bundle(): string
    {
        return $this->value.$this->id()->directory().'Bundle';
    }

    /**
     * The alias of the bundle's extension, its configuration key: "foresi_cash_flow", "acme_fleet".
     */
    public function alias(): string
    {
        $id = str_replace('-', '_', $this->id()->value);

        return $this->own() ? self::OWN_VENDOR.'_'.$id : $id;
    }

    /**
     * The asset namespace of the module's Stimulus controllers: "@foresi/cash-flow", "@acme-fleet".
     */
    public function assets(): string
    {
        return '@'.($this->own() ? self::OWN_VENDOR.'/' : '').$this->id()->value;
    }

    private static function kebab(string $segment): string
    {
        return strtolower((string) preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z\d])([A-Z])/'], '\1-\2', $segment));
    }
}
