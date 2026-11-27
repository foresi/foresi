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
 * the header, as a project's page shows its own, and for the people of the
 * organisation the main action of the header and the sections under the
 * subtasks.
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
        return $this->items(TaskSlots::HEADER_FACTS, $taskId, $projectId);
    }

    /**
     * @return array{headerActions: list<array{template: string, data: array<string, mixed>}>, slotSections: list<array{template: string, data: array<string, mixed>}>}
     */
    public function internal(string $taskId, string $projectId): array
    {
        return [
            'headerActions' => $this->items(TaskSlots::HEADER_ACTIONS, $taskId, $projectId),
            'slotSections' => $this->items(TaskSlots::SECTIONS, $taskId, $projectId),
        ];
    }

    /**
     * @return list<array{template: string, data: array<string, mixed>}>
     */
    private function items(string $slot, string $taskId, string $projectId): array
    {
        $context = [
            'taskId' => $taskId,
            'projectId' => $projectId,
            'return' => $this->urls->generate(TaskController::ROUTE, ['id' => $taskId]),
        ];
        $items = [];
        foreach ($this->slots->items($slot, $context) as $item) {
            if (null !== $item->template) {
                $items[] = ['template' => $item->template, 'data' => $item->data + $context];
            }
        }

        return $items;
    }
}
