<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

/**
 * A person marked a VAT period settled, such as one paid from another
 * account: when, who (the id of their user) and why.
 */
final readonly class SettledMark
{
    public function __construct(
        public \DateTimeImmutable $at,
        public string $by,
        public ?string $note,
    ) {
    }
}
