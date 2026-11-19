<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;

/**
 * The columns of the recurring invoices; each cell renders one RecurringRow. The name
 * comes first, as iDoklad lists its templates. The names and the description give way,
 * cut short with their full text in a title. There is no state column: the views say
 * it, and a failing row says why under its description.
 *
 * The context holds "severalFirms", which offers the firm column.
 */
final readonly class RecurringColumns implements DataSource
{
    public const string LIST = RecurringLists::KEY;

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'recurring.list.column.'.$key;

        return [
            new Attribute('name', $label('name'), AttributeType::Text, static fn (RecurringRow $row): string => $row->name, sort: 'LOWER(r.name)', defaultVisible: true, defaultPosition: 10, cell: static fn (RecurringRow $row): string => $row->name, locked: true, truncate: true),
            new Attribute('customer', $label('customer'), AttributeType::Reference, static fn (RecurringRow $row): string => $row->customer, defaultVisible: true, defaultPosition: 20, cell: static fn (RecurringRow $row): array => ['links' => [['text' => $row->customer, 'href' => $row->customerHref]], 'title' => $row->customer], phone: false, truncate: true),
            new Attribute('description', $label('description'), AttributeType::Text, static fn (RecurringRow $row): string => $row->description, defaultVisible: true, defaultPosition: 30, cell: static fn (RecurringRow $row): string => $row->description, phone: false, truncate: true),
            new Attribute('firm', $label('firm'), AttributeType::Text, static fn (RecurringRow $row): string => $row->firm, defaultVisible: true, defaultPosition: 40, cell: static fn (RecurringRow $row): string => $row->firm, phone: false, truncate: true, available: static fn (array $context): bool => true === ($context['severalFirms'] ?? false)),
            new Attribute('interval', $label('interval'), AttributeType::Text, static fn (RecurringRow $row): string => $row->interval, defaultVisible: true, defaultPosition: 50, cell: static fn (RecurringRow $row): string => $row->interval, phone: false),
            new Attribute('next', $label('next'), AttributeType::Text, static fn (RecurringRow $row): string => $row->next, sort: 'r.nextOccurrence', defaultVisible: true, defaultPosition: 60, cell: static fn (RecurringRow $row): string => $row->next, phone: false),
            new Attribute('delivery', $label('delivery'), AttributeType::Text, static fn (RecurringRow $row): string => $row->delivery, defaultVisible: true, defaultPosition: 70, cell: static fn (RecurringRow $row): string => $row->delivery, phone: false),
            new Attribute('amount', $label('amount'), AttributeType::Money, static fn (RecurringRow $row): string => $row->amount, defaultVisible: true, defaultPosition: 80, cell: static fn (RecurringRow $row): string => $row->amount),
        ];
    }
}
