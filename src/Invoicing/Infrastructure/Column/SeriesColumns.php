<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

use Foresi\Invoicing\Application\View\SeriesLists;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the number series on the firm's invoicing settings, those the page always
 * showed: the name, the kind of documents, the format, the next number and the default.
 */
final readonly class SeriesColumns implements DataSource
{
    public const string LIST = SeriesLists::KEY;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'series.column.'.$key;
        $kind = fn (SeriesRow $row): string => $this->translator->trans('series.kind.'.$row->series->kind()->value);

        return [
            new Attribute('name', $label('name'), AttributeType::Text, static fn (SeriesRow $row): string => $row->name, defaultVisible: true, defaultPosition: 10, cell: static fn (SeriesRow $row): string => $row->name, locked: true, truncate: true),
            new Attribute('kind', $label('kind'), AttributeType::Text, $kind, defaultVisible: true, defaultPosition: 20, cell: $kind),
            new Attribute('format', $label('format'), AttributeType::Text, static fn (SeriesRow $row): string => $row->series->format()->toString(), defaultVisible: true, defaultPosition: 30, cell: static fn (SeriesRow $row): string => $row->series->format()->toString()),
            new Attribute('next', $label('next'), AttributeType::Text, static fn (SeriesRow $row): string => $row->next, defaultVisible: true, defaultPosition: 40, cell: static fn (SeriesRow $row): string => $row->next),
            new Attribute('default', $label('default'), AttributeType::Boolean, static fn (SeriesRow $row): bool => $row->series->isDefault(), defaultVisible: true, defaultPosition: 50, cell: fn (SeriesRow $row): ?array => $row->series->isDefault() ? ['text' => $this->translator->trans('series.default'), 'tone' => 'green'] : null),
        ];
    }
}
