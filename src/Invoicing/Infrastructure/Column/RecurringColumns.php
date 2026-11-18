<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;

/**
 * The columns of the recurring invoices; each cell renders one RecurringRow. The name
 * comes first, as iDoklad lists its templates. The names and the description give way,
 * cut short with their full text in a title. There is no state column: the views say
 * it, and a failing row says why under its description.
 *
 * The context holds "severalFirms", which offers the firm column.
 */
final readonly class RecurringColumns implements ListColumns
{
    public const string LIST = RecurringLists::KEY;

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'recurring.list.column.'.$key;

        return [
            new Column('name', $label('name'), static fn (RecurringRow $row): string => $row->name, defaultPosition: 10, sortKey: 'name', locked: true, truncate: true),
            new Column('customer', $label('customer'), static fn (RecurringRow $row): array => ['links' => [['text' => $row->customer, 'href' => $row->customerHref]], 'title' => $row->customer], defaultPosition: 20, phone: false, truncate: true),
            new Column('description', $label('description'), static fn (RecurringRow $row): string => $row->description, defaultPosition: 30, phone: false, truncate: true),
            new Column('firm', $label('firm'), static fn (RecurringRow $row): string => $row->firm, defaultPosition: 40, phone: false, truncate: true, available: static fn (array $context): bool => true === ($context['severalFirms'] ?? false)),
            new Column('interval', $label('interval'), static fn (RecurringRow $row): string => $row->interval, defaultPosition: 50, phone: false),
            new Column('next', $label('next'), static fn (RecurringRow $row): string => $row->next, defaultPosition: 60, sortKey: 'next', phone: false),
            new Column('delivery', $label('delivery'), static fn (RecurringRow $row): string => $row->delivery, defaultPosition: 70, phone: false),
            new Column('amount', $label('amount'), static fn (RecurringRow $row): string => $row->amount, defaultPosition: 80, align: 'right'),
        ];
    }
}
