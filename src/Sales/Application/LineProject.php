<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

/**
 * A project an hourly line may link to, as Sales reads it from Work.
 */
final readonly class LineProject
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $clientId,
        public bool $archived,
        /** The address of the project's page. */
        public string $url,
    ) {
    }
}
