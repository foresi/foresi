<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\StageNames;
use Foresi\Sales\Domain\Stage;
use Foresi\Sales\Infrastructure\Api\SalesJson;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * The answer of a write to the stages: the stage, or all of them in their
 * new order, and a summary.
 */
final readonly class StageAnswer
{
    public function __construct(private SalesJson $json, private StageNames $names)
    {
    }

    public function of(Stage $stage, string $summary): OperationOutput
    {
        return new OperationOutput($this->json->stage($stage), \sprintf($summary, $this->names->of($stage)));
    }

    /**
     * @param list<Stage> $stages
     */
    public function all(array $stages, string $summary): OperationOutput
    {
        return new OperationOutput(['items' => array_map($this->json->stage(...), $stages)], $summary);
    }
}
