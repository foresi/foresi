<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\Module;

/**
 * The id of a module, such as "timesheet" or "acme-fleet": kebab case, as its
 * namespace gives it (ModuleNamespace).
 */
final readonly class ModuleId
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function of(string $id): self
    {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
            throw new InvalidManifest(\sprintf('"%s" is not a module id; use kebab case such as "exchange-rate".', $id));
        }

        return new self($id);
    }

    /**
     * The directory and namespace segment of the module, "exchange-rate" as
     * "ExchangeRate".
     */
    public function directory(): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $this->value)));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
