<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * Marks a class as a contract event and names it. The name reads
 * module.resource.action, lower case, and its prefix is the id of the module
 * that owns the class. A version is a positive integer per name, and a change
 * within a version is only additive.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class DomainEvent
{
    public function __construct(
        public string $name,
        public int $version = 1,
    ) {
    }
}
