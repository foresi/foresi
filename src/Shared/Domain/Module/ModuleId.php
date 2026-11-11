<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Module;

/**
 * The id of a module, such as "timesheet": kebab case, the package name
 * without the "foresi/" vendor.
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

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
