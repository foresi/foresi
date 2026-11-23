<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\LostReasonNames;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Infrastructure\Api\SalesJson;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * The answer of a write to a lost reason: the reason and a summary.
 */
final readonly class LostReasonAnswer
{
    public function __construct(private SalesJson $json, private LostReasonNames $names)
    {
    }

    public function of(LostReason $reason, string $summary): OperationOutput
    {
        return new OperationOutput($this->json->lostReason($reason), \sprintf($summary, $this->names->of($reason)));
    }
}
