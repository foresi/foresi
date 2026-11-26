<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Perf;

/**
 * What the performance seed wrote: who signs in, one record of each type to open,
 * and how long each type took.
 */
final readonly class PerfWorld
{
    /**
     * @param array<string, int>   $counts  by record type, as PerfSize::counts() names them
     * @param array<string, float> $seconds by record type, and "setup" for the owner and the firm
     */
    public function __construct(
        public PerfSize $size,
        public string $ownerUserId,
        public string $ownerEmail,
        public ?string $contactId,
        public ?string $invoiceId,
        public ?string $projectId,
        public ?string $taskId,
        public ?string $dealId,
        public ?string $receivedDocumentId,
        public array $counts,
        public array $seconds,
    ) {
    }

    public function totalSeconds(): float
    {
        return array_sum($this->seconds);
    }
}
