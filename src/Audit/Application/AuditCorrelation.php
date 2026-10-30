<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * The correlation id that groups the audit entries written while a unit of
 * work runs, for example `import:<importId>`. Holds state, so it is reset
 * between messages of a long-running worker.
 */
final class AuditCorrelation
{
    private ?string $current = null;

    /**
     * Runs the work with the given id as the current correlation id.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function within(string $correlationId, callable $work): mixed
    {
        if ('' === $correlationId || \strlen($correlationId) > 64) {
            throw new \InvalidArgumentException('A correlation id is 1 to 64 characters long.');
        }
        if (null !== $this->current && $this->current !== $correlationId) {
            throw new \LogicException(\sprintf('Cannot start correlation "%s" inside "%s".', $correlationId, $this->current));
        }

        $outer = $this->current;
        $this->current = $correlationId;
        try {
            return $work();
        } finally {
            $this->current = $outer;
        }
    }

    public function current(): ?string
    {
        return $this->current;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
