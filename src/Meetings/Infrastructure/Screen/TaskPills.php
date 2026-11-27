<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Screen;

use Foresi\Meetings\Application\MeetingsClock;
use Foresi\Shared\Application\Markdown;
use Foresi\Work\Contract\TaskSummary;
use Twig\Environment;

/**
 * The minutes as the meeting's page shows them: the Markdown rendered, and
 * each reference to a task the person sees followed by its pill. A task they
 * do not see, or one purged from the trash, leaves the text alone, with no
 * hint of the task (spec, Task reference token).
 */
final readonly class TaskPills
{
    public const string PILL = '@Meetings/meetings/_task_pill.html.twig';

    /** The placeholder exactly as the Markdown port renders and sanitises it. */
    private const string PLACEHOLDER = '#<span class="task-ref" data-task="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})">([^<]*)</span>#';

    public function __construct(
        private Markdown $markdown,
        private Environment $twig,
        private MeetingsClock $clock,
    ) {
    }

    /**
     * @param array<string, TaskSummary> $tasks the tasks the person sees, by id
     */
    public function minutes(string $minutes, array $tasks): string
    {
        $today = $this->today();

        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($tasks, $today): string {
            $task = $tasks[$match[1]] ?? null;
            if (!$task instanceof TaskSummary) {
                return $match[2];
            }

            return $match[0].$this->twig->render(self::PILL, ['task' => $task, 'today' => $today]);
        }, $this->markdown->toHtml($minutes));
    }

    public function today(): string
    {
        return $this->clock->today()->format('Y-m-d');
    }
}
