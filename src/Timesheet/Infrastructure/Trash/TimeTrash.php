<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Trash;

use Foresi\Timesheet\Application\TimeQueries;
use Foresi\Timesheet\Application\TrashedTime;
use Foresi\Timesheet\Infrastructure\Controller\TimeController;
use Foresi\Ui\Format\DurationText;
use Foresi\Work\Contract\ProjectTrashSection;
use Foresi\Work\Contract\ProjectTrashSources;
use Foresi\Work\Infrastructure\Controller\ProjectController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The time deleted from a project, in its trash after the tasks: each entry
 * with its day, who logged it, how long and on which task, restored by its
 * button by whom may change it, once its task is out of the trash.
 */
final readonly class TimeTrash implements ProjectTrashSources
{
    public const string KEY = 'time';

    public function __construct(
        private TimeQueries $time,
        private DurationText $duration,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function count(string $projectId): int
    {
        return $this->time->trashCount($projectId);
    }

    public function section(string $projectId): ?ProjectTrashSection
    {
        $trash = $this->time->trash($projectId);
        if ([] === $trash) {
            return null;
        }
        $here = $this->urls->generate(ProjectController::TRASH_ROUTE, ['id' => $projectId]);
        $date = $this->translator->trans('format.date');
        $datetime = $this->translator->trans('format.datetime');

        return new ProjectTrashSection(
            self::KEY,
            $this->translator->trans('timesheet.time.trash.section'),
            [
                ['key' => 'workedOn', 'label' => $this->translator->trans('timesheet.time.trash.column.worked_on')],
                ['key' => 'person', 'label' => $this->translator->trans('timesheet.time.trash.column.person'), 'truncate' => true, 'phone' => false],
                ['key' => 'duration', 'label' => $this->translator->trans('timesheet.time.trash.column.duration'), 'align' => 'right'],
                ['key' => 'task', 'label' => $this->translator->trans('timesheet.time.trash.column.task'), 'truncate' => true, 'phone' => false],
                ['key' => 'deletedBy', 'label' => $this->translator->trans('work.trash.column.deleted_by'), 'truncate' => true, 'phone' => false],
                ['key' => 'deletedAt', 'label' => $this->translator->trans('work.trash.column.deleted_at')],
            ],
            array_map(fn (TrashedTime $time): array => [
                'id' => $time->id,
                'label' => $this->duration->shown($time->minutes).' · '.$time->workedOn->format($date),
                'secondary' => $time->taskTitle,
                'cells' => [
                    'workedOn' => $time->workedOn->format($date),
                    'person' => $time->personName,
                    'duration' => $this->duration->shown($time->minutes),
                    'task' => $time->taskTitle,
                    'deletedBy' => $time->deletedByName,
                    'deletedAt' => $time->deletedAt->format($datetime),
                ],
                'actions' => $time->restorable ? [[
                    'label' => $this->translator->trans('work.trash.restore'),
                    'href' => $this->urls->generate('app_time_restore', ['id' => $time->id]),
                    'method' => 'POST',
                    'icon' => 'archive-restore',
                    'fields' => ['return' => $here],
                ]] : [],
            ], $trash),
            TimeController::TOKEN,
            10,
        );
    }
}
