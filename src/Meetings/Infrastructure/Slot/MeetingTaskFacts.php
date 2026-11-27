<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Slot;

use Foresi\Meetings\Application\MeetingsStore;
use Foresi\Meetings\Application\MeetingVisibility;
use Foresi\Meetings\Infrastructure\Controller\MeetingController;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Work\Contract\TaskSlots;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "From meeting: Production weekly – 2026-12-01" in the header of a task made
 * from a meeting's minutes, the meeting linked to its page (spec, Create task
 * from the minutes). Only a person who sees the meeting sees it; someone who
 * sees the task but not the meeting sees nothing there.
 */
final readonly class MeetingTaskFacts implements SlotProvider
{
    public const string TEMPLATE = '@Meetings/tasks/_meeting_fact.html.twig';

    private const int WEIGHT = 20;

    public function __construct(
        private MeetingsStore $store,
        private MeetingVisibility $visibility,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function items(string $slot, array $context): iterable
    {
        $taskId = $context['taskId'] ?? null;
        if (TaskSlots::HEADER_FACTS !== $slot || !\is_string($taskId) || !$this->visibility->mayUse()) {
            return [];
        }
        foreach ($this->store->actionItemsOfTask($taskId) as $item) {
            $meeting = $item->meeting();
            if ($this->visibility->sees($meeting)) {
                return [new SlotItem(self::TEMPLATE, [
                    'meeting' => $meeting->title(),
                    'href' => $this->urls->generate(MeetingController::MEETING_ROUTE, ['id' => $meeting->id()]),
                ], self::WEIGHT)];
            }
        }

        return [];
    }
}
