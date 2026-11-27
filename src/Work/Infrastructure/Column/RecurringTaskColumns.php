<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Work\Application\View\RecurringTaskLists;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Infrastructure\Controller\ProjectController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the recurring tasks, those of the recurring invoices': the title, its
 * project, the schedule, the next date, the state and how many tasks it created.
 */
final readonly class RecurringTaskColumns implements DataSource
{
    public const string LIST = RecurringTaskLists::KEY;

    private const array TONES = ['active' => 'green', 'paused' => 'amber', 'ended' => 'gray'];

    public function __construct(private TranslatorInterface $translator, private UrlGeneratorInterface $urls)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }

        return [
            new Attribute('title', 'work.recurring.field.title', AttributeType::Text, static fn (RecurringTaskRow $row): string => $row->title, defaultVisible: true, defaultPosition: 10, locked: true, truncate: true),
            new Attribute('project', 'work.recurring.field.project', AttributeType::Text, static fn (RecurringTaskRow $row): string => $row->projectName, defaultVisible: true, defaultPosition: 20, cell: fn (RecurringTaskRow $row): array => ['links' => [['text' => $row->projectName, 'href' => $this->urls->generate(ProjectController::PROJECT_ROUTE, ['id' => $row->projectId])]]], truncate: true),
            new Attribute('schedule', 'work.recurring.field.schedule', AttributeType::Text, static fn (RecurringTaskRow $row): string => $row->schedule, defaultVisible: true, defaultPosition: 30, phone: false, truncate: true),
            new Attribute('next', 'work.recurring.field.next', AttributeType::Date, static fn (RecurringTaskRow $row): ?\DateTimeImmutable => $row->next, defaultVisible: true, defaultPosition: 40, cell: fn (RecurringTaskRow $row): string => $row->next?->format($this->translator->trans('format.date')) ?? ''),
            new Attribute('state', 'work.recurring.field.state', AttributeType::Text, static fn (RecurringTaskRow $row): string => $row->state->value, defaultVisible: true, defaultPosition: 50, cell: fn (RecurringTaskRow $row): array => ['badges' => [
                ['label' => $this->translator->trans('work.recurring.state.'.$row->state->value), 'tone' => self::TONES[$row->state->value]],
                ...($row->failing && RecurringTaskState::Ended !== $row->state ? [['label' => $this->translator->trans('work.recurring.failing'), 'tone' => 'red']] : []),
            ]]),
            new Attribute('created', 'work.recurring.field.created_count', AttributeType::Number, static fn (RecurringTaskRow $row): int => $row->created, defaultVisible: true, defaultPosition: 60, cell: static fn (RecurringTaskRow $row): array => ['text' => (string) $row->created, 'numeric' => true], phone: false),
        ];
    }
}
