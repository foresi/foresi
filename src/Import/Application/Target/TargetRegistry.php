<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Target;

/** All import targets, found by their key. */
final class TargetRegistry
{
    /** @var array<string, ImportTarget> */
    private array $targets = [];

    /**
     * @param iterable<ImportTarget> $targets
     */
    public function __construct(
        iterable $targets,
    ) {
        foreach ($targets as $target) {
            $this->targets[$target->key()] = $target;
        }
    }

    public function get(string $key): ImportTarget
    {
        return $this->targets[$key] ?? throw new \InvalidArgumentException(\sprintf('Unknown import target "%s".', $key));
    }

    /**
     * @return list<ImportTarget>
     */
    public function all(): array
    {
        return array_values($this->targets);
    }
}
