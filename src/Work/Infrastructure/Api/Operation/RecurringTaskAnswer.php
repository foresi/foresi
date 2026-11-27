<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Infrastructure\Api\RecurringTaskApiController;
use Foresi\Work\Infrastructure\Api\RecurringTaskJson;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What every recurring task write answers: the recurring task as GET
 * /api/v1/recurring-tasks/{id} and foresi_get_recurring_task answer it.
 */
final readonly class RecurringTaskAnswer
{
    public function __construct(private RecurringTaskJson $json, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param string $summary with %s for the title of the recurring task
     */
    public function of(RecurringTask $recurring, string $summary, bool $created = false): OperationOutput
    {
        return new OperationOutput(
            $this->json->detail($recurring),
            \sprintf($summary, $recurring->title()),
            $created ? $this->urls->generate(RecurringTaskApiController::SHOW, ['recurringTaskId' => $recurring->id()]) : null,
        );
    }
}
