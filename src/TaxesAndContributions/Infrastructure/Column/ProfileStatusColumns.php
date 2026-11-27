<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\TaxesAndContributions\Application\View\ProfileStatusLists;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the statuses of the owner, those the dialog always showed: the status, the day
 * it holds from and the last day it holds.
 */
final readonly class ProfileStatusColumns implements DataSource
{
    public const string LIST = ProfileStatusLists::KEY;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'taxes.profile.column.'.$key;
        $until = fn (StatusRow $row): string => $row->until ?? $this->translator->trans('taxes.profile.until_next');

        return [
            new Attribute('value', $label('value'), AttributeType::Text, static fn (StatusRow $row): string => $row->value, defaultVisible: true, defaultPosition: 10, cell: static fn (StatusRow $row): string => $row->value, locked: true, truncate: true),
            new Attribute('from', $label('from'), AttributeType::Text, static fn (StatusRow $row): string => $row->from, defaultVisible: true, defaultPosition: 20, cell: static fn (StatusRow $row): string => $row->from),
            new Attribute('until', $label('until'), AttributeType::Text, $until, defaultVisible: true, defaultPosition: 30, cell: fn (StatusRow $row): string|array => $row->until ?? ['empty' => $until($row)], phone: false),
        ];
    }
}
