<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Catalogue;

use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Module\ModuleId;

/**
 * One contract event as the catalogue knows it: its name and version, the
 * module that owns it, its class and the snake case names of its facts.
 */
final readonly class EventType
{
    /**
     * @param class-string<DomainEventPayload> $class
     * @param list<string>                     $fields
     */
    public function __construct(
        public string $name,
        public int $version,
        public ModuleId $module,
        public string $class,
        public array $fields,
    ) {
    }
}
