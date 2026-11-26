<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Column;

use Foresi\Deals\Application\View\LostReasonLists;
use Foresi\Deals\Domain\LostReason;
use Foresi\Deals\Infrastructure\Screen\DealScreen;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;

/**
 * The columns of the reasons a deal is lost on Settings → Sales, those the page always showed: the name,
 * the key a seeded one has and whether it is archived.
 */
final readonly class LostReasonColumns implements DataSource
{
    public const string LIST = LostReasonLists::KEY;

    public function __construct(private DealScreen $screen)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'deals.settings.column.'.$key;
        $name = fn (LostReason $record): string => $this->screen->reason($record);

        return [
            new Attribute('name', $label('name'), AttributeType::Text, $name, defaultVisible: true, defaultPosition: 10, cell: $name, locked: true, truncate: true),
            new Attribute('key', $label('key'), AttributeType::Text, static fn (LostReason $record): string => $record->key() ?? '', defaultVisible: true, defaultPosition: 20, cell: static fn (LostReason $record): string => $record->key() ?? '', phone: false),
            new Attribute('state', $label('state'), AttributeType::Text, fn (LostReason $record): string => $record->isArchived() ? $this->screen->trans('deals.settings.archived') : '', defaultVisible: true, defaultPosition: 30, cell: fn (LostReason $record): ?array => $record->isArchived() ? ['badges' => [['label' => $this->screen->trans('deals.settings.archived'), 'tone' => 'gray']]] : null),
        ];
    }
}
