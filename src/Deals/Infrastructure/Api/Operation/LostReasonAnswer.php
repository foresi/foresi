<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\LostReasonNames;
use Foresi\Deals\Domain\LostReason;
use Foresi\Deals\Infrastructure\Api\SalesJson;
use Foresi\Shared\Contract\Operation\OperationOutput;

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
