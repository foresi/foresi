<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Work\Application\View\LabelLists;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of Settings → Labels, those the page and its trash always showed: the name, how
 * the label looks and how many tasks carry it; in the trash, who deleted it and when instead of
 * the preview, and the name as the label looks.
 */
final readonly class LabelColumns implements DataSource
{
    public const string LIST = LabelLists::KEY;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $trash = static fn (array $context): bool => true === ($context['trash'] ?? false);
        $notTrash = static fn (array $context): bool => true !== ($context['trash'] ?? false);
        $badge = static fn (LabelRow $row): array => ['badges' => [['label' => $row->name, 'tone' => $row->colour->value]]];

        return [
            new Attribute('name', 'work.labels.column.name', AttributeType::Text, static fn (LabelRow $row): string => $row->name, defaultVisible: true, defaultPosition: 10, cell: static fn (LabelRow $row): array|string => $row->isTrashed() ? $badge($row) : $row->name, locked: true, truncate: true),
            new Attribute('preview', 'work.labels.column.preview', AttributeType::Text, static fn (LabelRow $row): string => $row->name, defaultVisible: true, defaultPosition: 20, cell: $badge, available: $notTrash),
            new Attribute('tasks', 'work.labels.column.tasks', AttributeType::Number, static fn (LabelRow $row): int => $row->tasks, defaultVisible: true, defaultPosition: 30, cell: static fn (LabelRow $row): array => ['text' => (string) $row->tasks, 'numeric' => true]),
            new Attribute('deleted_by', 'work.trash.column.deleted_by', AttributeType::Text, static fn (LabelRow $row): string => $row->deletedBy ?? '', defaultVisible: true, defaultPosition: 40, cell: static fn (LabelRow $row): string => $row->deletedBy ?? '', phone: false, truncate: true, available: $trash),
            new Attribute('deleted_at', 'work.trash.column.deleted_at', AttributeType::DateTime, static fn (LabelRow $row): ?\DateTimeImmutable => $row->deletedAt, defaultVisible: true, defaultPosition: 50, cell: fn (LabelRow $row): string => $row->deletedAt?->format($this->translator->trans('format.datetime')) ?? '', available: $trash),
        ];
    }
}
