<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Domain\DealStatus;

/**
 * Which deals a list holds: those not in the trash, narrowed by each
 * criterion given; null leaves it open. The text is looked for in the title,
 * without regard to case.
 */
final readonly class DealFilter
{
    public function __construct(
        public ?DealStatus $status = null,
        public ?string $stageId = null,
        public ?string $ownerUserId = null,
        public ?string $clientPartyId = null,
        public ?string $text = null,
    ) {
    }
}
