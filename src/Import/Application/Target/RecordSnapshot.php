<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Target;

/** The state of a record before an import changed it, enough to put it back. */
final readonly class RecordSnapshot
{
    /**
     * @param array<string, mixed> $data JSON-serialisable
     */
    public function __construct(public array $data)
    {
    }

    /** The state of a record that did not exist before. */
    public static function none(): self
    {
        return new self([]);
    }

    public function isNone(): bool
    {
        return [] === $this->data;
    }
}
