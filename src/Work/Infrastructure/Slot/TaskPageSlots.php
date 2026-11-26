<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Slot;

use Foresi\Ui\Slot\SlotRegistry;
use Foresi\Work\Contract\TaskSlots;
use Foresi\Work\Infrastructure\Controller\TaskController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What other modules add to a task's page through TaskSlots: the facts under
 * the header, as a project's page shows its own.
 */
final readonly class TaskPageSlots
{
    public function __construct(private SlotRegistry $slots, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @return list<array{template: string, data: array<string, mixed>}>
     */
    public function headerFacts(string $taskId, string $projectId): array
    {
        $context = [
            'taskId' => $taskId,
            'projectId' => $projectId,
            'return' => $this->urls->generate(TaskController::ROUTE, ['id' => $taskId]),
        ];
        $facts = [];
        foreach ($this->slots->items(TaskSlots::HEADER_FACTS, $context) as $item) {
            if (null !== $item->template) {
                $facts[] = ['template' => $item->template, 'data' => $item->data + $context];
            }
        }

        return $facts;
    }
}
